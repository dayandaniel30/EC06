import React, { useCallback, useEffect, useState } from "react";
import "./app.css";
import axios from "axios";
import { apiRequest, getApiBaseUrl } from "../services/api";
import Auth from "./Auth";
import HomePage from "./HomePage";

const formationLevelOptions = ["beginner", "intermediate", "advanced"];

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

  if (!user.isTrainer) {
    return (
      <div className="page-shell">
        <section className="card auth-card">
          <h2>Acces formateur uniquement</h2>
          <p className="question">Connectez-vous avec un compte formateur pour gerer vos formations.</p>
          <button className="solid-btn" onClick={handleLogout} type="button">Se deconnecter</button>
        </section>
      </div>
    );
  }

  return (
    <div className="page-shell">
      <header className="hero">
        <p className="badge">Dashboard SkillHub</p>
        <h1>Espace formateur de {user.name}</h1>
        <p className="hero-copy">Liste des formations du formateur connecte, avec recherche et filtre prix.</p>
        <button className="solid-btn logout-btn" onClick={handleLogout} type="button">Se deconnecter</button>
      </header>

      {errorMessage ? <div className="feedback warn">{errorMessage}</div> : null}

      <main className="dashboard-grid">
        <section className="card full-span">
          <h2>CRUD Formations</h2>

          <div className="crud-form" style={{ marginBottom: "1rem" }}>
            <input
              className="crud-input"
              onChange={function (event) { setSearchTerm(event.target.value); }}
              placeholder="Barre de recherche (titre, description, niveau)"
              type="text"
              value={searchTerm}
            />
            <input
              className="crud-input"
              min="0"
              onChange={function (event) { setMinPrice(event.target.value); }}
              placeholder="Prix min"
              step="0.01"
              type="number"
              value={minPrice}
            />
            <input
              className="crud-input"
              min="0"
              onChange={function (event) { setMaxPrice(event.target.value); }}
              placeholder="Prix max"
              step="0.01"
              type="number"
              value={maxPrice}
            />
          </div>

          <button
            className="solid-btn"
            onClick={function () {
              setIsFormationModalOpen(true);
              setErrorMessage("");
            }}
            type="button"
          >
            Ajouter formation
          </button>

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

          {trainerLoading ? <p className="question">Chargement...</p> : null}
          <div className="crud-table-wrap">
            <table className="crud-table">
              <thead>
                <tr>
                  <th>Formations</th>
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
                      <td>{formation.title}</td>
                      <td>{formation.description || "Aucune description"}</td>
                      <td>{formation.price} EUR</td>
                      <td>{formation.duration} h</td>
                      <td><span className="level-chip">{formation.level}</span></td>
                      <td>
                        <button className="mini-btn" onClick={function () { handleEditFormation(formation); }} type="button">
                          Modifier
                        </button>
                        <button className="mini-btn danger" onClick={function () { handleDeleteFormation(formation); }} type="button">
                          Supprimer
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      </main>
    </div>
  );
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
