import React, { useState } from "react";
import { apiRequest } from "../services/api";

function Auth({ onLogin }) {
  const [authMode, setAuthMode] = useState("login");
  const [authLoading, setAuthLoading] = useState(false);
  const [authError, setAuthError] = useState("");
  const [authForm, setAuthForm] = useState({
    name: "",
    email: "",
    password: "",
    role: "formateur"
  });

  async function handleLoginSubmit(event) {
    event.preventDefault();
    setAuthError("");

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
      
      // On reconstruit l'objet utilisateur pour l'état global
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

  return (
    <div className="page-shell">
      <section className="card auth-card">
        <h2>{authMode === "login" ? "Connexion" : "Inscription"}</h2>
        <p className="question">Connectez-vous pour acceder au dashboard apprenant ou formateur.</p>
        {authError ? <div className="feedback warn">{authError}</div> : null}
        <form
          className="crud-form auth-form"
          onSubmit={authMode === "login" ? handleLoginSubmit : handleSignupSubmit}
        >
          {authMode === "signup" ? (
            <input
              className="crud-input"
              onChange={(event) => setAuthForm({ ...authForm, name: event.target.value })}
              placeholder="Nom complet"
              type="text"
              value={authForm.name}
            />
          ) : null}
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
          </select>
          <button className="solid-btn" type="submit">
            {authLoading
              ? "Traitement..."
              : authMode === "login"
              ? "Se connecter"
              : "S'inscrire"}
          </button>
        </form>
        <button
          className="mini-btn"
          onClick={() => {
            setAuthError("");
            setAuthMode(authMode === "login" ? "signup" : "login");
          }}
          type="button"
        >
          {authMode === "login" ? "Creer un compte" : "J'ai deja un compte"}
        </button>
      </section>
    </div>
  );
}

export default Auth;
