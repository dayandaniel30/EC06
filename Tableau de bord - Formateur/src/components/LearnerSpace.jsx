import React, { useCallback, useEffect, useState } from "react";
import { apiRequest } from "../services/api";

// En deca de ce nombre de jours restants, l'apprenant est alerte pour qu'il
// puisse se remettre au travail avant la desinscription automatique.
const atRiskWindowDays = 7;

function normalizeEnrollment(row) {
  const inactiveDays = Number(row.inactive_days);
  const daysBefore = Number(row.days_before_unenrollment);
  const progress = Number(row.progress);
  const hasDaysBefore =
    row.days_before_unenrollment !== null &&
    row.days_before_unenrollment !== undefined &&
    Number.isFinite(daysBefore);

  return {
    id: Number(row.id || 0),
    formationId: Number(row.formation_id || 0),
    title: row.title || "Formation",
    level: row.level || "",
    duration: row.duration || "",
    progress: Number.isFinite(progress) ? progress : 0,
    inactiveDays: Number.isFinite(inactiveDays) ? inactiveDays : null,
    daysBeforeUnenrollment: hasDaysBefore ? daysBefore : null
  };
}

function normalizeCatalogueRow(row) {
  return {
    id: Number(row.id || 0),
    title: row.title || "Formation",
    description: row.description || "",
    level: row.level || "",
    duration: row.duration || "",
    isEnrolled: Boolean(row.is_enrolled)
  };
}

/**
 * Etat de l'inscription au regard de la desinscription automatique.
 *
 * daysBeforeUnenrollment vaut null quand la regle ne s'applique pas :
 * formation terminee, ou inactivite indeterminable.
 */
function describeCountdown(enrollment) {
  if (enrollment.progress >= 100) {
    return { label: "Terminée", tone: "done" };
  }
  if (enrollment.daysBeforeUnenrollment === null) {
    return { label: "Activité inconnue", tone: "unknown" };
  }
  if (enrollment.daysBeforeUnenrollment === 0) {
    return { label: "Retrait imminent", tone: "danger" };
  }
  if (enrollment.daysBeforeUnenrollment <= atRiskWindowDays) {
    return { label: "Plus que " + enrollment.daysBeforeUnenrollment + " j", tone: "warn" };
  }
  return { label: enrollment.daysBeforeUnenrollment + " j restants", tone: "ok" };
}

function LearnerSpace({ user, onLogout }) {
  const [enrollments, setEnrollments] = useState([]);
  const [catalogue, setCatalogue] = useState([]);
  const [loading, setLoading] = useState(true);
  const [busyFormationId, setBusyFormationId] = useState(0);
  const [errorMessage, setErrorMessage] = useState("");
  const [noticeMessage, setNoticeMessage] = useState("");
  // Regles renvoyees par l'API : le frontend ne les code pas en dur.
  const [maxActive, setMaxActive] = useState(5);
  const [unenrollAfterDays, setUnenrollAfterDays] = useState(30);

  const applyMeta = useCallback(function (payload) {
    const meta = payload && payload.meta ? payload.meta : {};
    const limit = Number(meta.max_active);
    const threshold = Number(meta.unenroll_after_days);

    if (Number.isFinite(limit) && limit > 0) {
      setMaxActive(limit);
    }
    if (Number.isFinite(threshold) && threshold > 0) {
      setUnenrollAfterDays(threshold);
    }
  }, []);

  const reload = useCallback(
    async function () {
      const [mine, available] = await Promise.all([
        apiRequest("/api/learner/enrollments", "GET", user.token, undefined),
        apiRequest("/api/learner/formations", "GET", user.token, undefined)
      ]);

      applyMeta(mine);
      applyMeta(available);

      setEnrollments((Array.isArray(mine.data) ? mine.data : []).map(normalizeEnrollment));
      setCatalogue((Array.isArray(available.data) ? available.data : []).map(normalizeCatalogueRow));
    },
    [user.token, applyMeta]
  );

  useEffect(
    function () {
      let mounted = true;

      async function load() {
        setLoading(true);
        try {
          await reload();
        } catch (error) {
          if (mounted) {
            setErrorMessage(error.message || "Chargement impossible.");
          }
        } finally {
          if (mounted) {
            setLoading(false);
          }
        }
      }

      load();

      return function () {
        mounted = false;
      };
    },
    [reload]
  );

  async function runAction(formationId, action) {
    setBusyFormationId(formationId);
    setErrorMessage("");
    setNoticeMessage("");
    try {
      const message = await action();
      await reload();
      setNoticeMessage(message);
    } catch (error) {
      setErrorMessage(error.message || "Action impossible.");
    } finally {
      setBusyFormationId(0);
    }
  }

  function handleEnroll(formation) {
    runAction(formation.id, async function () {
      await apiRequest("/api/learner/enrollments", "POST", user.token, {
        formation_id: formation.id
      });
      return "Inscription à " + formation.title + " effectuée.";
    });
  }

  function handleComplete(enrollment) {
    runAction(enrollment.formationId, async function () {
      await apiRequest(
        "/api/learner/enrollments/" + encodeURIComponent(enrollment.id) + "/complete",
        "PATCH",
        user.token,
        {}
      );
      return enrollment.title + " marquée comme terminée.";
    });
  }

  function handleLeave(enrollment) {
    if (!window.confirm("Se désinscrire de " + enrollment.title + " ?")) {
      return;
    }

    runAction(enrollment.formationId, async function () {
      await apiRequest(
        "/api/learner/enrollments/" + encodeURIComponent(enrollment.id),
        "DELETE",
        user.token,
        undefined
      );
      return "Désinscription de " + enrollment.title + " effectuée.";
    });
  }

  const activeEnrollments = enrollments.filter(function (enrollment) {
    return enrollment.progress < 100;
  });
  const atRisk = activeEnrollments.filter(function (enrollment) {
    return enrollment.daysBeforeUnenrollment !== null && enrollment.daysBeforeUnenrollment <= atRiskWindowDays;
  });
  const limitReached = activeEnrollments.length >= maxActive;

  return (
    <div className="app-frame">
      <aside className="sidebar">
        <div className="brand-lockup">
          <span className="brand-mark" aria-hidden="true">S</span>
          <span>skill<span>hub</span></span>
        </div>
        <div className="sidebar-label">Espace apprenant</div>
        <nav className="side-nav" aria-label="Navigation de l'espace apprenant">
          <a className="side-nav-link active" href="#mes-formations"><span aria-hidden="true">▤</span> Mes formations</a>
          <a className="side-nav-link" href="#catalogue"><span aria-hidden="true">⌕</span> Catalogue</a>
        </nav>
        <div className="sidebar-bottom">
          <button className="sidebar-logout" onClick={onLogout} type="button"><span aria-hidden="true">↪</span> Se déconnecter</button>
        </div>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div className="breadcrumb">Espace apprenant <span>/</span> Mes formations</div>
          <div className="topbar-actions">
            <div className="profile">
              <span className="avatar">{user.name.charAt(0).toUpperCase()}</span>
              <span><strong>{user.name}</strong><small>Apprenant</small></span>
            </div>
          </div>
        </header>

        <section className="welcome-row">
          <div>
            <p className="eyebrow">VOTRE PARCOURS</p>
            <h1>Bonjour, {user.name.split(" ")[0]} <span aria-hidden="true">✦</span></h1>
            <p>Vous suivez {activeEnrollments.length} formation{activeEnrollments.length > 1 ? "s" : ""} sur {maxActive} possibles.</p>
          </div>
        </section>

        {errorMessage ? <div className="feedback warn">{errorMessage}</div> : null}
        {noticeMessage ? <div className="feedback good">{noticeMessage}</div> : null}

        {atRisk.length > 0 ? (
          <div className="feedback warn">
            <strong>Attention :</strong> {atRisk.length} formation{atRisk.length > 1 ? "s" : ""} sur le point d&apos;être
            retirée{atRisk.length > 1 ? "s" : ""} de votre compte. Toute activité sur la plateforme remet le compteur à zéro.
          </div>
        ) : null}

        <section id="mes-formations" className="content-panel">
          <div className="panel-heading">
            <div><p className="eyebrow">SUIVI</p><h2>Mes formations</h2></div>
            <span className="period-chip">Retrait après {unenrollAfterDays} jours d&apos;inactivité</span>
          </div>

          {loading ? <p className="question">Chargement de vos inscriptions...</p> : null}

          {!loading && enrollments.length === 0 ? (
            <div className="empty-state">
              <span aria-hidden="true">▧</span>
              <h3>Aucune inscription</h3>
              <p>Parcourez le catalogue ci-dessous pour vous inscrire à votre première formation.</p>
            </div>
          ) : null}

          {!loading && enrollments.length > 0 ? (
            <div className="crud-table-wrap">
              <table className="crud-table">
                <thead>
                  <tr>
                    <th>Formation</th>
                    <th>Niveau</th>
                    <th>Progression</th>
                    <th>Avant retrait</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {enrollments.map(function (enrollment) {
                    const countdown = describeCountdown(enrollment);
                    const busy = busyFormationId === enrollment.formationId;

                    return (
                      <tr key={enrollment.id}>
                        <td><strong className="formation-title">{enrollment.title}</strong></td>
                        <td><span className="level-chip">{enrollment.level}</span></td>
                        <td>
                          <div className="progress"><i style={{ width: enrollment.progress + "%" }} /></div>
                          <small className="learner-email">{enrollment.progress} %</small>
                        </td>
                        <td><span className={"activity-chip " + countdown.tone}>{countdown.label}</span></td>
                        <td>
                          {enrollment.progress < 100 ? (
                            <button className="mini-btn" disabled={busy} onClick={function () { handleComplete(enrollment); }} type="button">
                              Marquer terminée
                            </button>
                          ) : null}
                          <button className="mini-btn danger" disabled={busy} onClick={function () { handleLeave(enrollment); }} type="button">
                            Se désinscrire
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : null}
        </section>

        <section id="catalogue" className="content-panel">
          <div className="panel-heading">
            <div><p className="eyebrow">DÉCOUVRIR</p><h2>Catalogue</h2></div>
            <span className="period-chip">{activeEnrollments.length} / {maxActive} en cours</span>
          </div>

          {limitReached ? (
            <p className="panel-note">
              Vous suivez déjà {maxActive} formations simultanément. Terminez-en une ou désinscrivez-vous
              pour pouvoir en rejoindre une nouvelle.
            </p>
          ) : null}

          {!loading && catalogue.length === 0 ? (
            <div className="empty-state">
              <span aria-hidden="true">◎</span>
              <h3>Catalogue vide</h3>
              <p>Aucune formation n&apos;est publiée pour le moment.</p>
            </div>
          ) : null}

          <div className="catalogue-grid">
            {catalogue.map(function (formation) {
              const busy = busyFormationId === formation.id;

              return (
                <article className="catalogue-card" key={formation.id}>
                  <h3>{formation.title}</h3>
                  <p>{formation.description || "Aucune description."}</p>
                  <div className="catalogue-meta">
                    <span className="level-chip">{formation.level}</span>
                    <small>{formation.duration}</small>
                  </div>
                  {formation.isEnrolled ? (
                    <span className="activity-chip done">Déjà inscrit</span>
                  ) : (
                    <button
                      className="solid-btn"
                      disabled={busy || limitReached}
                      onClick={function () { handleEnroll(formation); }}
                      type="button"
                    >
                      {busy ? "Inscription..." : "S'inscrire"}
                    </button>
                  )}
                </article>
              );
            })}
          </div>
        </section>
      </main>
    </div>
  );
}

export default LearnerSpace;
