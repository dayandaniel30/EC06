import React, { useState } from "react";
import { apiRequest } from "../services/api";

function Auth({ onLogin }) {
  const [authMode, setAuthMode] = useState("login");
  const [authLoading, setAuthLoading] = useState(false);
  const [authError, setAuthError] = useState("");
  const [authInfo, setAuthInfo] = useState("");
  const [authForm, setAuthForm] = useState({
    name: "",
    email: "",
    password: "",
    role: "apprenant"
  });
  const [resetForm, setResetForm] = useState({
    token: "",
    newPassword: ""
  });

  function switchMode(nextMode) {
    setAuthError("");
    setAuthInfo("");
    setAuthMode(nextMode);
  }

  async function handleLoginSubmit(event) {
    event.preventDefault();
    setAuthError("");
    setAuthInfo("");

    if (!authForm.email.trim() || !authForm.password.trim()) {
      setAuthError("Email et mot de passe obligatoires.");
      return;
    }

    setAuthLoading(true);
    try {
      const payload = await apiRequest("/api/login", "POST", "", {
        email: authForm.email.trim(),
        password: authForm.password
      });

      const apiUser = payload.user || {};
      const token = payload.token || payload.access_token || "";
      if (!token) {
        throw new Error("Aucun token renvoye par Laravel. Expose token/access_token dans /api/login.");
      }
      const session = {
        name: String(apiUser.name || authForm.email.split("@")[0] || "Utilisateur"),
        email: String(apiUser.email || authForm.email.trim()),
        role: String(apiUser.role || "apprenant").toLowerCase()
      };

      window.localStorage.setItem("skillhub_token", token);
      window.localStorage.setItem("skillhub_user", JSON.stringify(session));

      const userContext = {
          role: session.role,
          name: session.name,
          email: session.email,
          token: token,
          isTrainer: session.role === "formateur" || session.role === "admin"
      };

      onLogin(userContext);
    } catch (error) {
      setAuthError(error.message || "Connexion impossible.");
    } finally {
      setAuthLoading(false);
    }
  }

  async function handleSignupSubmit(event) {
    event.preventDefault();
    setAuthError("");
    setAuthInfo("");

    if (!authForm.name.trim() || !authForm.email.trim() || !authForm.password.trim()) {
      setAuthError("Nom, email et mot de passe obligatoires.");
      return;
    }

    setAuthLoading(true);
    try {
      const payload = await apiRequest("/api/register", "POST", "", {
        name: authForm.name.trim(),
        email: authForm.email.trim(),
        password: authForm.password,
        role: authForm.role
      });

      const apiUser = payload.user || {};
      const token = payload.token || payload.access_token || "";
      if (!token) {
        throw new Error("Aucun token renvoye par Laravel. Expose token/access_token dans /api/register.");
      }
      const session = {
        name: String(apiUser.name || authForm.name.trim()),
        email: String(apiUser.email || authForm.email.trim()),
        role: String(apiUser.role || authForm.role).toLowerCase()
      };

      window.localStorage.setItem("skillhub_token", token);
      window.localStorage.setItem("skillhub_user", JSON.stringify(session));

      const userContext = {
          role: session.role,
          name: session.name,
          email: session.email,
          token: token,
          isTrainer: session.role === "formateur" || session.role === "admin"
      };

      onLogin(userContext);
    } catch (error) {
      setAuthError(error.message || "Inscription impossible.");
    } finally {
      setAuthLoading(false);
    }
  }

  async function handleForgotSubmit(event) {
    event.preventDefault();
    setAuthError("");
    setAuthInfo("");

    if (!authForm.email.trim()) {
      setAuthError("Email obligatoire pour recevoir le lien de reinitialisation.");
      return;
    }

    setAuthLoading(true);
    try {
      const payload = await apiRequest("/api/sso/forgot-password", "POST", "", {
        username: authForm.email.trim()
      });

      const baseMessage = payload && payload.message
        ? payload.message
        : "Si ce compte existe, un email de reinitialisation a ete envoye.";

      if (payload && payload.resetToken) {
        setResetForm({ token: payload.resetToken, newPassword: "" });
        setAuthInfo(baseMessage + " (mode demo : token pre-rempli ci-dessous)");
        setAuthMode("reset");
      } else {
        setAuthInfo(baseMessage);
      }
    } catch (error) {
      setAuthError(error.message || "Demande de reinitialisation impossible.");
    } finally {
      setAuthLoading(false);
    }
  }

  async function handleResetSubmit(event) {
    event.preventDefault();
    setAuthError("");
    setAuthInfo("");

    if (!resetForm.token.trim() || !resetForm.newPassword.trim()) {
      setAuthError("Token et nouveau mot de passe obligatoires.");
      return;
    }
    if (resetForm.newPassword.length < 6) {
      setAuthError("Le nouveau mot de passe doit contenir au moins 6 caracteres.");
      return;
    }

    setAuthLoading(true);
    try {
      await apiRequest("/api/sso/reset-password", "POST", "", {
        token: resetForm.token.trim(),
        newPassword: resetForm.newPassword
      });
      setAuthInfo("Mot de passe reinitialise avec succes. Vous pouvez vous reconnecter.");
      setResetForm({ token: "", newPassword: "" });
      setAuthMode("login");
    } catch (error) {
      setAuthError(error.message || "Reinitialisation impossible.");
    } finally {
      setAuthLoading(false);
    }
  }

  function renderLoginForm() {
    return (
      <form className="crud-form auth-form" onSubmit={handleLoginSubmit}>
        <input
          className="crud-input"
          onChange={(event) => setAuthForm({ ...authForm, email: event.target.value })}
          placeholder="Email"
          type="email"
          value={authForm.email}
        />
        <input
          className="crud-input"
          onChange={(event) => setAuthForm({ ...authForm, password: event.target.value })}
          placeholder="Mot de passe"
          type="password"
          value={authForm.password}
        />
        <button className="solid-btn" type="submit">
          {authLoading ? "Traitement..." : "Se connecter"}
        </button>
      </form>
    );
  }

  function renderSignupForm() {
    return (
      <form className="crud-form auth-form" onSubmit={handleSignupSubmit}>
        <input
          className="crud-input"
          onChange={(event) => setAuthForm({ ...authForm, name: event.target.value })}
          placeholder="Nom complet"
          type="text"
          value={authForm.name}
        />
        <input
          className="crud-input"
          onChange={(event) => setAuthForm({ ...authForm, email: event.target.value })}
          placeholder="Email"
          type="email"
          value={authForm.email}
        />
        <input
          className="crud-input"
          onChange={(event) => setAuthForm({ ...authForm, password: event.target.value })}
          placeholder="Mot de passe"
          type="password"
          value={authForm.password}
        />
        <select
          className="crud-input"
          onChange={(event) => setAuthForm({ ...authForm, role: event.target.value })}
          value={authForm.role}
        >
          <option value="formateur">Formateur</option>
          <option value="apprenant">Apprenant</option>
        </select>
        <button className="solid-btn" type="submit">
          {authLoading ? "Traitement..." : "S'inscrire"}
        </button>
      </form>
    );
  }

  function renderForgotForm() {
    return (
      <form className="crud-form auth-form" onSubmit={handleForgotSubmit}>
        <input
          className="crud-input"
          onChange={(event) => setAuthForm({ ...authForm, email: event.target.value })}
          placeholder="Email du compte"
          type="email"
          value={authForm.email}
        />
        <button className="solid-btn" type="submit">
          {authLoading ? "Envoi..." : "Recevoir un lien de reinitialisation"}
        </button>
      </form>
    );
  }

  function renderResetForm() {
    return (
      <form className="crud-form auth-form" onSubmit={handleResetSubmit}>
        <input
          className="crud-input"
          onChange={(event) => setResetForm({ ...resetForm, token: event.target.value })}
          placeholder="Token de reinitialisation"
          type="text"
          value={resetForm.token}
        />
        <input
          className="crud-input"
          onChange={(event) => setResetForm({ ...resetForm, newPassword: event.target.value })}
          placeholder="Nouveau mot de passe (min 6 caracteres)"
          type="password"
          value={resetForm.newPassword}
        />
        <button className="solid-btn" type="submit">
          {authLoading ? "Traitement..." : "Mettre a jour le mot de passe"}
        </button>
      </form>
    );
  }

  let title;
  let subtitle;
  let body;
  if (authMode === "login") {
    title = "Connexion";
    subtitle = "Connectez-vous pour acceder au dashboard apprenant ou formateur.";
    body = renderLoginForm();
  } else if (authMode === "signup") {
    title = "Inscription";
    subtitle = "Creez un compte pour commencer.";
    body = renderSignupForm();
  } else if (authMode === "forgot") {
    title = "Mot de passe oublie";
    subtitle = "Renseignez votre email, nous vous enverrons un lien de reinitialisation.";
    body = renderForgotForm();
  } else {
    title = "Reinitialisation du mot de passe";
    subtitle = "Saisissez le token recu et choisissez un nouveau mot de passe.";
    body = renderResetForm();
  }

  return (
    <div className="page-shell">
      <section className="card auth-card">
        <h2>{title}</h2>
        <p className="question">{subtitle}</p>
        {authError ? <div className="feedback warn">{authError}</div> : null}
        {authInfo ? <div className="feedback ok">{authInfo}</div> : null}
        {body}

        <div className="auth-actions">
          {authMode === "login" ? (
            <>
              <button className="mini-btn" onClick={() => switchMode("forgot")} type="button">
                Mot de passe oublie ?
              </button>
              <button className="mini-btn" onClick={() => switchMode("signup")} type="button">
                Creer un compte
              </button>
            </>
          ) : null}
          {authMode === "signup" ? (
            <button className="mini-btn" onClick={() => switchMode("login")} type="button">
              J'ai deja un compte
            </button>
          ) : null}
          {authMode === "forgot" ? (
            <>
              <button className="mini-btn" onClick={() => switchMode("reset")} type="button">
                J'ai deja un token
              </button>
              <button className="mini-btn" onClick={() => switchMode("login")} type="button">
                Retour a la connexion
              </button>
            </>
          ) : null}
          {authMode === "reset" ? (
            <button className="mini-btn" onClick={() => switchMode("login")} type="button">
              Retour a la connexion
            </button>
          ) : null}
        </div>
      </section>
    </div>
  );
}

export default Auth;
