import React, { useCallback, useEffect, useState } from "react";
import "./app.css";
import axios from "axios";
import { apiRequest, getApiBaseUrl } from "../services/api";
import Auth from "./Auth";
import HomePage from "./HomePage";
import LearnerSpace from "./LearnerSpace";

const formationLevelOptions = ["beginner", "intermediate", "advanced"];

// Fenetre d'alerte : en deca de ce nombre de jours restants, l'apprenant est
// signale au formateur pour qu'il puisse le relancer avant la desinscription.
const atRiskWindowDays = 7;

function getUserContext() {
  try {
    const userRaw = window.localStorage.getItem("skillhub_user");
    const token = window.localStorage.getItem("skillhub_token");
    if (!userRaw || !token) {
      return null;
    }

    const parsed = JSON.parse(userRaw);
    const role = String(parsed.role || "apprenant").toLowerCase();

    return {
      role: role,
      name: String(parsed.name || "Utilisateur"),
      email: String(parsed.email || ""),
      token: token,
      isTrainer: role === "formateur" || role === "admin"
    };
  } catch (error) {
    return null;
  }
}

function App() {
  const [user, setUser] = useState(function () {
    return getUserContext();
  });
  const [showAuth, setShowAuth] = useState(false);
  const [errorMessage, setErrorMessage] = useState("");
  const [trainerFormations, setTrainerFormations] = useState([]);
  const [trainerLoading, setTrainerLoading] = useState(false);
  const [trainerLearners, setTrainerLearners] = useState([]);
  // Seuil renvoye par l'API : le frontend ne code pas la regle des 30 jours en dur.
  const [unenrollAfterDays, setUnenrollAfterDays] = useState(30);
  const [creatingFormation, setCreatingFormation] = useState(false);
  const [isFormationModalOpen, setIsFormationModalOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState("");
  const [minPrice, setMinPrice] = useState("");
  const [maxPrice, setMaxPrice] = useState("");
  const [formationDraft, setFormationDraft] = useState({
    title: "",
    description: "",
    price: "",
    duration: "",
    level: "beginner"
  });
  const [formationErrors, setFormationErrors] = useState({
    title: "",
    description: "",
    price: "",
    duration: "",
    level: ""
  });

  const fetchTrainerFormations = useCallback(async function (token) {
    const params = new URLSearchParams();
    if (searchTerm.trim()) {
      params.set("q", searchTerm.trim());
    }
    if (String(minPrice).trim() !== "") {
      params.set("min_price", String(minPrice).trim());
    }
    if (String(maxPrice).trim() !== "") {
      params.set("max_price", String(maxPrice).trim());
    }

    const suffix = params.toString() ? "?" + params.toString() : "";
    const payload = await apiRequest("/api/my-formations" + suffix, "GET", token, undefined);
    const rows = Array.isArray(payload.data) ? payload.data : [];

    setTrainerFormations(
      rows.map(function (row) {
        return normalizeFormation(row);
      })
    );
  }, [searchTerm, minPrice, maxPrice]);

  const fetchTrainerLearners = useCallback(async function (token) {
    const payload = await apiRequest("/api/formateur/enrollments", "GET", token, undefined);
    const rows = Array.isArray(payload.data) ? payload.data : [];
    const threshold = payload.meta ? Number(payload.meta.unenroll_after_days) : NaN;

    if (Number.isFinite(threshold) && threshold > 0) {
      setUnenrollAfterDays(threshold);
    }

    setTrainerLearners(
      rows.map(function (row) {
        return normalizeLearner(row);
      })
    );
  }, []);

  useEffect(
    function () {
      if (!user || !user.isTrainer) {
        return;
      }

      let mounted = true;

      async function loadTrainerData() {
        setTrainerLoading(true);
        setErrorMessage("");
        try {
          await fetchTrainerFormations(user.token);
        } catch (error) {
          if (mounted) {
            setErrorMessage(error.message || "Erreur de chargement.");
          }
        } finally {
          if (mounted) {
            setTrainerLoading(false);
          }
        }
      }

      loadTrainerData();

      return function () {
        mounted = false;
      };
    },
    [user, fetchTrainerFormations]
  );

  // Effet distinct de celui des formations : ce dernier se rejoue a chaque
  // frappe dans la recherche, alors que le suivi d'activite n'en depend pas.
  useEffect(
    function () {
      if (!user || !user.isTrainer) {
        return undefined;
      }

      let mounted = true;

      async function loadLearners() {
        try {
          await fetchTrainerLearners(user.token);
        } catch (error) {
          if (mounted) {
            setErrorMessage(error.message || "Suivi d'activite indisponible.");
          }
        }
      }

      loadLearners();

      return function () {
        mounted = false;
      };
    },
    [user, fetchTrainerLearners]
  );

  function resetFormationForm() {
    setFormationDraft({
      title: "",
      description: "",
      price: "",
      duration: "",
      level: "beginner"
    });
    setFormationErrors({
      title: "",
      description: "",
      price: "",
      duration: "",
      level: ""
    });
  }

  function validateFormationDraft() {
    const nextErrors = {
      title: "",
      description: "",
      price: "",
      duration: "",
      level: ""
    };

    const title = formationDraft.title.trim();
    const description = formationDraft.description.trim();
    const priceRaw = String(formationDraft.price).trim();
    const durationRaw = String(formationDraft.duration).trim();
    const level = String(formationDraft.level).trim().toLowerCase();
    const price = Number(priceRaw);
    const duration = Number(durationRaw);

    if (!title) {
      nextErrors.title = "Le titre est obligatoire.";
    }
    if (!description) {
      nextErrors.description = "La description est obligatoire.";
    }
    if (!priceRaw) {
      nextErrors.price = "Le prix est obligatoire.";
    } else if (!Number.isFinite(price) || price < 0) {
      nextErrors.price = "Le prix doit etre un decimal positif.";
    }
    if (!durationRaw) {
      nextErrors.duration = "La duree est obligatoire.";
    } else if (!Number.isInteger(duration) || duration <= 0) {
      nextErrors.duration = "La duree doit etre un entier (heures).";
    }
    if (!formationLevelOptions.includes(level)) {
      nextErrors.level = "Le niveau doit etre beginner, intermediate ou advanced.";
    }

    setFormationErrors(nextErrors);
    return !Object.values(nextErrors).some(Boolean);
  }

  async function handleCreateFormation(event) {
    event.preventDefault();
    if (!validateFormationDraft()) {
      return;
    }

    const tokenFromStorage = window.localStorage.getItem("skillhub_token");
    if (!tokenFromStorage) {
      setErrorMessage("Session invalide: token manquant.");
      return;
    }

    setCreatingFormation(true);
    setErrorMessage("");

    try {
      const response = await axios.post(
        getApiBaseUrl() + "/api/formations",
        {
          title: formationDraft.title.trim(),
          description: formationDraft.description.trim(),
          price: Number(formationDraft.price),
          duration: Number(formationDraft.duration),
          level: formationDraft.level.trim().toLowerCase()
        },
        { headers: { Authorization: "Bearer " + tokenFromStorage } }
      );

      if (response.status !== 201) {
        setErrorMessage("Reponse inattendue du serveur.");
        return;
      }

      await fetchTrainerFormations(tokenFromStorage);
      resetFormationForm();
      setIsFormationModalOpen(false);
    } catch (error) {
      const status = error && error.response ? error.response.status : 0;
      if (status === 422) {
        const apiErrors = error.response && error.response.data ? error.response.data.errors : null;
        setFormationErrors({
          title: apiErrors && apiErrors.title ? String(apiErrors.title[0]) : "",
          description: apiErrors && apiErrors.description ? String(apiErrors.description[0]) : "",
          price: apiErrors && apiErrors.price ? String(apiErrors.price[0]) : "",
          duration: apiErrors && apiErrors.duration ? String(apiErrors.duration[0]) : "",
          level: apiErrors && apiErrors.level ? String(apiErrors.level[0]) : ""
        });
        setErrorMessage("Erreur validation.");
      } else {
        const backendMessage =
          error && error.response && error.response.data && error.response.data.message
            ? error.response.data.message
            : "";
        setErrorMessage(backendMessage || error.message || "Creation de formation impossible.");
      }
    } finally {
      setCreatingFormation(false);
    }
  }

  async function handleEditFormation(formation) {
    const title = window.prompt("Titre", formation.title);
    if (title === null) {
      return;
    }
    const description = window.prompt("Description", formation.description || "");
    if (description === null) {
      return;
    }
    const price = window.prompt("Prix", String(formation.price || 0));
    if (price === null) {
      return;
    }
    const duration = window.prompt("Duree (heures)", String(formation.duration || 1));
    if (duration === null) {
      return;
    }
    const level = window.prompt("Niveau (beginner/intermediate/advanced)", formation.level || "beginner");
    if (level === null) {
      return;
    }

    setErrorMessage("");
    try {
      const payload = await apiRequest(
        "/api/formations/" + encodeURIComponent(formation.id),
        "PUT",
        user ? user.token : "",
        {
          title: title.trim(),
          description: description.trim(),
          price: Number(String(price).trim()),
          duration: Number(String(duration).trim()),
          level: String(level).trim().toLowerCase()
        }
      );
      const updated = normalizeFormation(payload.data || payload);
      setTrainerFormations(function (prev) {
        return prev.map(function (item) {
          return item.id === formation.id ? updated : item;
        });
      });
    } catch (error) {
      setErrorMessage(error.message || "Mise a jour de formation impossible.");
    }
  }

  async function handleDeleteFormation(formation) {
    if (!window.confirm("Supprimer la formation " + formation.title + " ?")) {
      return;
    }

    setErrorMessage("");
    try {
      await apiRequest("/api/formations/" + encodeURIComponent(formation.id), "DELETE", user ? user.token : "", undefined);
      setTrainerFormations(function (prev) {
        return prev.filter(function (item) {
          return item.id !== formation.id;
        });
      });
    } catch (error) {
      setErrorMessage(error.message || "Suppression de formation impossible.");
    }
  }

  async function handleLogout() {
    try {
      await apiRequest("/api/logout", "POST", user ? user.token : "", {});
    } catch (error) {
      // noop
    }
    window.localStorage.removeItem("skillhub_token");
    window.localStorage.removeItem("skillhub_user");
    setUser(null);
    setErrorMessage("");
  }

  function handleAuthLogin(nextUser) {
    setShowAuth(false);
    setUser(nextUser);
  }

  if (!user) {
    if (showAuth) {
      return <Auth onLogin={handleAuthLogin} />;
    }
    return <HomePage onRequestLogin={() => setShowAuth(true)} />;
  }

  // Un apprenant disposait jusqu'ici d'un ecran sans issue, alors que l'API
  // exposait deja tout son parcours (catalogue, inscription, progression).
  if (!user.isTrainer) {
    return <LearnerSpace onLogout={handleLogout} user={user} />;
  }

  const totalRevenue = trainerFormations.reduce(function (total, formation) {
    return total + Number(formation.price || 0);
  }, 0);
  const publishedCount = trainerFormations.length;
  const learnersAtRisk = trainerLearners.filter(function (learner) {
    return learner.daysBeforeUnenrollment !== null && learner.daysBeforeUnenrollment <= atRiskWindowDays;
  });
  const atRiskShare = trainerLearners.length
    ? Math.round((learnersAtRisk.length / trainerLearners.length) * 100)
    : 0;
  const averageDuration = publishedCount
    ? Math.round(trainerFormations.reduce(function (total, formation) { return total + Number(formation.duration || 0); }, 0) / publishedCount)
    : 0;

  return (
    <div className="app-frame">
      <aside className="sidebar">
        <div className="brand-lockup">
          <span className="brand-mark" aria-hidden="true">S</span>
          <span>skill<span>hub</span></span>
        </div>
        <div className="sidebar-label">Espace formateur</div>
        <nav className="side-nav" aria-label="Navigation du tableau de bord">
          <a className="side-nav-link active" href="#overview"><span aria-hidden="true">◼</span> Vue d'ensemble</a>
          <a className="side-nav-link" href="#formations"><span aria-hidden="true">▤</span> Mes formations</a>
          <a className="side-nav-link" href="#apprenants"><span aria-hidden="true">◎</span> Apprenants</a>
          <a className="side-nav-link" href="#insights"><span aria-hidden="true">↗</span> Statistiques</a>
          <a className="side-nav-link" href="#settings"><span aria-hidden="true">⚙</span> Paramètres</a>
        </nav>
        <div className="sidebar-bottom">
          <div className="help-box"><strong>Besoin d'aide ?</strong><span>Notre équipe est là pour vous.</span><button type="button">Contacter le support</button></div>
          <button className="sidebar-logout" onClick={handleLogout} type="button"><span aria-hidden="true">↪</span> Se déconnecter</button>
        </div>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div className="breadcrumb">Tableau de bord <span>/</span> Vue d'ensemble</div>
          <div className="topbar-actions"><button className="icon-btn" aria-label="Notifications" type="button">♢<i /></button><div className="profile"><span className="avatar">{user.name.charAt(0).toUpperCase()}</span><span><strong>{user.name}</strong><small>Formateur</small></span><span className="chevron">⌄</span></div></div>
        </header>

        <section id="overview" className="welcome-row">
          <div><p className="eyebrow">JEUDI 20 AOÛT 2026</p><h1>Bonjour, {user.name.split(" ")[0]} <span aria-hidden="true">✦</span></h1><p>Voici ce qui se passe dans votre espace aujourd'hui.</p></div>
          <button className="solid-btn primary-action" onClick={function () { setIsFormationModalOpen(true); setErrorMessage(""); }} type="button"><span aria-hidden="true">+</span> Nouvelle formation</button>
        </section>

        {errorMessage ? <div className="feedback warn">{errorMessage}</div> : null}

        <section className="stats-grid" aria-label="Indicateurs clés">
          <article className="stat-card"><div className="stat-icon teal">▤</div><div><span>Formations publiées</span><strong>{publishedCount}</strong><small className="positive">↑ Votre catalogue est actif</small></div></article>
          <article className="stat-card"><div className="stat-icon orange">◷</div><div><span>Durée moyenne</span><strong>{averageDuration} <em>h</em></strong><small>Par formation</small></div></article>
          <article className="stat-card"><div className="stat-icon blue">€</div><div><span>Valeur du catalogue</span><strong>{totalRevenue.toFixed(0)} <em>€</em></strong><small>Prix cumulés</small></div></article>
          <article className="stat-card accent-stat"><div><span>Désinscription sous {atRiskWindowDays} j</span><strong>{learnersAtRisk.length}</strong><div className="progress"><i style={{ width: atRiskShare + "%" }} /></div><small>{learnersAtRisk.length === 0 ? "Aucun apprenant menacé" : "À relancer avant retrait"}</small></div><span className="checkmark">{learnersAtRisk.length === 0 ? "✓" : "!"}</span></article>
        </section>

        <section id="formations" className="content-panel">
          <div className="panel-heading"><div><p className="eyebrow">VOTRE CONTENU</p><h2>Mes formations</h2></div><button className="text-btn" type="button">Voir les statistiques <span>→</span></button></div>
          <div className="toolbar"><label className="search-box"><span aria-hidden="true">⌕</span><input onChange={function (event) { setSearchTerm(event.target.value); }} placeholder="Rechercher une formation..." type="search" value={searchTerm} /></label><label className="filter-box"><span>Prix</span><input min="0" onChange={function (event) { setMinPrice(event.target.value); }} placeholder="Min" step="0.01" type="number" value={minPrice} /><b>-</b><input min="0" onChange={function (event) { setMaxPrice(event.target.value); }} placeholder="Max" step="0.01" type="number" value={maxPrice} /></label></div>

          {isFormationModalOpen ? (
            <div
              className="modal-overlay"
              onClick={function () {
                if (!creatingFormation) {
                  setIsFormationModalOpen(false);
                  resetFormationForm();
                }
              }}
              role="presentation"
            >
              <div className="modal-content" onClick={function (event) { event.stopPropagation(); }}>
                <div className="modal-head">
                  <h3>Nouvelle formation</h3>
                  <button
                    className="mini-btn"
                    disabled={creatingFormation}
                    onClick={function () {
                      setIsFormationModalOpen(false);
                      resetFormationForm();
                    }}
                    type="button"
                  >
                    Fermer
                  </button>
                </div>
                <form className="crud-form modal-form" onSubmit={handleCreateFormation}>
                  <div className="form-field">
                    <input
                      className={formationErrors.title ? "crud-input input-error" : "crud-input"}
                      onChange={function (event) {
                        setFormationDraft({ ...formationDraft, title: event.target.value });
                        setFormationErrors({ ...formationErrors, title: "" });
                      }}
                      placeholder="Titre"
                      type="text"
                      value={formationDraft.title}
                    />
                    {formationErrors.title ? <small className="field-error">{formationErrors.title}</small> : null}
                  </div>
                  <div className="form-field">
                    <input
                      className={formationErrors.price ? "crud-input input-error" : "crud-input"}
                      min="0"
                      onChange={function (event) {
                        setFormationDraft({ ...formationDraft, price: event.target.value });
                        setFormationErrors({ ...formationErrors, price: "" });
                      }}
                      placeholder="Prix"
                      step="0.01"
                      type="number"
                      value={formationDraft.price}
                    />
                    {formationErrors.price ? <small className="field-error">{formationErrors.price}</small> : null}
                  </div>
                  <div className="form-field">
                    <input
                      className={formationErrors.duration ? "crud-input input-error" : "crud-input"}
                      min="1"
                      onChange={function (event) {
                        setFormationDraft({ ...formationDraft, duration: event.target.value });
                        setFormationErrors({ ...formationErrors, duration: "" });
                      }}
                      placeholder="Duree (heures)"
                      step="1"
                      type="number"
                      value={formationDraft.duration}
                    />
                    {formationErrors.duration ? <small className="field-error">{formationErrors.duration}</small> : null}
                  </div>
                  <div className="form-field">
                    <select
                      className={formationErrors.level ? "crud-input input-error" : "crud-input"}
                      onChange={function (event) {
                        setFormationDraft({ ...formationDraft, level: event.target.value });
                        setFormationErrors({ ...formationErrors, level: "" });
                      }}
                      value={formationDraft.level}
                    >
                      <option value="beginner">beginner</option>
                      <option value="intermediate">intermediate</option>
                      <option value="advanced">advanced</option>
                    </select>
                    {formationErrors.level ? <small className="field-error">{formationErrors.level}</small> : null}
                  </div>
                  <div className="form-field form-field-full">
                    <textarea
                      className={formationErrors.description ? "crud-input input-error" : "crud-input"}
                      onChange={function (event) {
                        setFormationDraft({ ...formationDraft, description: event.target.value });
                        setFormationErrors({ ...formationErrors, description: "" });
                      }}
                      placeholder="Description"
                      rows={3}
                      value={formationDraft.description}
                    />
                    {formationErrors.description ? <small className="field-error">{formationErrors.description}</small> : null}
                  </div>
                  <button className="solid-btn" disabled={creatingFormation} type="submit">
                    {creatingFormation ? "Creation..." : "Creer"}
                  </button>
                </form>
              </div>
            </div>
          ) : null}

          {trainerLoading ? <p className="question">Chargement de vos formations...</p> : null}
          {!trainerLoading && trainerFormations.length === 0 ? <div className="empty-state"><span aria-hidden="true">▧</span><h3>Aucune formation trouvée</h3><p>Créez votre première formation pour commencer à développer votre catalogue.</p><button className="solid-btn" onClick={function () { setIsFormationModalOpen(true); }} type="button">Créer une formation</button></div> : null}
          <div className="crud-table-wrap">
            <table className="crud-table">
              <thead>
                <tr>
                  <th>Formation</th>
                  <th>Description</th>
                  <th>Prix</th>
                  <th>Duree</th>
                  <th>Niveau</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                {trainerFormations.map(function (formation) {
                  return (
                    <tr key={formation.id}>
                      <td><strong className="formation-title">{formation.title}</strong></td>
                      <td>{formation.description || "Aucune description"}</td>
                      <td>{formation.price} EUR</td>
                      <td>{formation.duration} h</td>
                      <td><span className="level-chip">{formation.level}</span></td>
                      <td>
                        <button className="mini-btn" aria-label={"Modifier " + formation.title} onClick={function () { handleEditFormation(formation); }} type="button">Modifier</button>
                        <button className="mini-btn danger" aria-label={"Supprimer " + formation.title} onClick={function () { handleDeleteFormation(formation); }} type="button">Supprimer</button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>

        <section id="apprenants" className="content-panel">
          <div className="panel-heading">
            <div><p className="eyebrow">SUIVI D'ACTIVITÉ</p><h2>Apprenants inscrits</h2></div>
            <span className="period-chip">Seuil : {unenrollAfterDays} jours</span>
          </div>
          <p className="panel-note">
            Un apprenant sans activité depuis plus de {unenrollAfterDays} jours est automatiquement
            désinscrit de ses formations en cours. Les formations terminées ne sont jamais retirées.
          </p>

          {trainerLearners.length === 0 ? (
            <div className="empty-state">
              <span aria-hidden="true">◎</span>
              <h3>Aucun apprenant inscrit</h3>
              <p>Les inscriptions à vos formations apparaîtront ici avec leur suivi d'activité.</p>
            </div>
          ) : (
            <div className="crud-table-wrap">
              <table className="crud-table">
                <thead>
                  <tr>
                    <th>Apprenant</th>
                    <th>Formation</th>
                    <th>Progression</th>
                    <th>Dernière activité</th>
                    <th>Statut</th>
                  </tr>
                </thead>
                <tbody>
                  {trainerLearners.map(function (learner) {
                    const status = describeActivityStatus(learner);
                    return (
                      <tr key={learner.enrollmentId}>
                        <td>
                          <strong className="formation-title">{learner.userName}</strong>
                          <small className="learner-email">{learner.userEmail}</small>
                        </td>
                        <td>{learner.formationTitle}</td>
                        <td>{learner.progress} %</td>
                        <td>{formatLastActivity(learner)}</td>
                        <td><span className={"activity-chip " + status.tone}>{status.label}</span></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <section id="insights" className="bottom-grid"><article className="content-panel insight-panel"><div className="panel-heading"><div><p className="eyebrow">ACTIVITÉ RÉCENTE</p><h2>Votre progression</h2></div><span className="period-chip">Cette année ⌄</span></div><div className="chart-placeholder"><div className="chart-grid"><span>100</span><span>75</span><span>50</span><span>25</span><span>0</span></div><div className="chart-line"><i /><i /><i /><i /><i /><i /><i /><i /></div><div className="chart-months"><span>JAN</span><span>FÉV</span><span>MAR</span><span>AVR</span><span>MAI</span><span>JUN</span><span>JUL</span><span>AOÛ</span></div></div></article><article id="settings" className="content-panel tips-panel"><p className="eyebrow">CONSEIL DU JOUR</p><h2>Donnez envie d'apprendre</h2><p>Les formations avec une description détaillée et une durée claire sont plus faciles à choisir.</p><button className="text-btn" type="button">Optimiser mon contenu <span>→</span></button></article></section>
      </main>
    </div>
  );
}

function normalizeLearner(row) {
  const inactiveDays = Number(row.inactive_days);
  const daysBefore = Number(row.days_before_unenrollment);
  const progress = Number(row.progress);
  const hasDaysBefore =
    row.days_before_unenrollment !== null &&
    row.days_before_unenrollment !== undefined &&
    Number.isFinite(daysBefore);

  return {
    enrollmentId: Number(row.enrollment_id || 0),
    formationTitle: row.formation_title || "Formation",
    userName: row.user_name || "Apprenant",
    userEmail: row.user_email || "",
    progress: Number.isFinite(progress) ? progress : 0,
    lastActivityAt: row.last_activity_at || "",
    inactiveDays: Number.isFinite(inactiveDays) ? inactiveDays : null,
    daysBeforeUnenrollment: hasDaysBefore ? daysBefore : null
  };
}

/**
 * Etat de l'apprenant vis-a-vis de la desinscription automatique.
 *
 * `daysBeforeUnenrollment` vaut null quand la regle ne s'applique pas :
 * formation terminee, ou aucune date d'activite exploitable.
 */
function describeActivityStatus(learner) {
  if (learner.progress >= 100) {
    return { label: "Formation terminée", tone: "done" };
  }
  if (learner.daysBeforeUnenrollment === null) {
    return { label: "Activité inconnue", tone: "unknown" };
  }
  if (learner.daysBeforeUnenrollment === 0) {
    return { label: "Désinscription imminente", tone: "danger" };
  }
  if (learner.daysBeforeUnenrollment <= atRiskWindowDays) {
    return { label: "Inactif · J-" + learner.daysBeforeUnenrollment, tone: "warn" };
  }
  return { label: "Actif", tone: "ok" };
}

function formatLastActivity(learner) {
  if (!learner.lastActivityAt) {
    return "Jamais — date d'inscription retenue";
  }
  if (learner.inactiveDays === null) {
    return learner.lastActivityAt;
  }
  if (learner.inactiveDays === 0) {
    return "Aujourd'hui";
  }
  return "Il y a " + learner.inactiveDays + (learner.inactiveDays > 1 ? " jours" : " jour");
}

function normalizeFormation(row) {
  const rawPrice = Number(row.price);
  const rawDuration = Number(row.duration);
  const rawLevel = String(row.level || "").toLowerCase();
  const price = Number.isFinite(rawPrice) ? rawPrice.toFixed(2) : "0.00";
  const duration = Number.isInteger(rawDuration) && rawDuration > 0 ? String(rawDuration) : "1";
  const level = formationLevelOptions.includes(rawLevel) ? rawLevel : "beginner";

  return {
    id: Number(row.id || 0),
    title: row.title || "Formation",
    description: row.description || "",
    price: price,
    duration: duration,
    level: level
  };
}

export default App;
