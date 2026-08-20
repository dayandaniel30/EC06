import React, { useEffect, useRef, useState } from "react";

const NAV_ITEMS = [
  { id: "hero", label: "Accueil" },
  { id: "features", label: "Fonctionnalites" },
  { id: "how", label: "Comment ca marche" },
  { id: "faq", label: "FAQ" },
  { id: "contact", label: "Contact" }
];

function HomePage({ onRequestLogin }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const firstMenuLinkRef = useRef(null);
  const burgerButtonRef = useRef(null);

  useEffect(() => {
    function handleKey(event) {
      if (event.key === "Escape") {
        setMenuOpen(false);
      }
    }
    if (menuOpen) {
      document.addEventListener("keydown", handleKey);
      document.body.style.overflow = "hidden";
      const focusTimer = window.setTimeout(() => {
        if (firstMenuLinkRef.current) {
          firstMenuLinkRef.current.focus();
        }
      }, 60);
      return () => {
        document.removeEventListener("keydown", handleKey);
        document.body.style.overflow = "";
        window.clearTimeout(focusTimer);
      };
    }
    return undefined;
  }, [menuOpen]);

  function closeMenu() {
    setMenuOpen(false);
    if (burgerButtonRef.current) {
      burgerButtonRef.current.focus();
    }
  }

  function handleNavClick(targetId) {
    setMenuOpen(false);
    const target = document.getElementById(targetId);
    if (target) {
      target.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  function handleLoginClick() {
    setMenuOpen(false);
    if (typeof onRequestLogin === "function") {
      onRequestLogin();
    }
  }

  return (
    <div className="home-shell">
      <a className="skip-link" href="#hero">
        Aller au contenu principal
      </a>

      <header className="home-nav">
        <div className="home-nav-inner">
          <a className="home-brand" href="#hero" onClick={(event) => { event.preventDefault(); handleNavClick("hero"); }}>
            <span className="home-brand-mark" aria-hidden="true">SH</span>
            <span className="home-brand-name">SkillHub</span>
          </a>

          <nav className="home-nav-desktop" aria-label="Navigation principale">
            <ul>
              {NAV_ITEMS.map((item) => (
                <li key={item.id}>
                  <a
                    href={"#" + item.id}
                    onClick={(event) => { event.preventDefault(); handleNavClick(item.id); }}
                  >
                    {item.label}
                  </a>
                </li>
              ))}
            </ul>
            <button className="home-cta" onClick={handleLoginClick} type="button">
              Se connecter
            </button>
          </nav>

          <button
            ref={burgerButtonRef}
            type="button"
            className={"home-burger" + (menuOpen ? " is-open" : "")}
            aria-label={menuOpen ? "Fermer le menu" : "Ouvrir le menu"}
            aria-expanded={menuOpen ? "true" : "false"}
            aria-controls="home-mobile-menu"
            onClick={() => setMenuOpen((prev) => !prev)}
          >
            <span aria-hidden="true" />
            <span aria-hidden="true" />
            <span aria-hidden="true" />
          </button>
        </div>
      </header>

      <div
        className={"home-menu-overlay" + (menuOpen ? " is-open" : "")}
        onClick={closeMenu}
        aria-hidden={menuOpen ? "false" : "true"}
      />

      <aside
        id="home-mobile-menu"
        className={"home-mobile-menu" + (menuOpen ? " is-open" : "")}
        aria-hidden={menuOpen ? "false" : "true"}
        aria-label="Menu de navigation"
      >
        <nav>
          <ul>
            {NAV_ITEMS.map((item, index) => (
              <li key={item.id}>
                <a
                  ref={index === 0 ? firstMenuLinkRef : null}
                  href={"#" + item.id}
                  onClick={(event) => { event.preventDefault(); handleNavClick(item.id); }}
                  tabIndex={menuOpen ? 0 : -1}
                >
                  {item.label}
                </a>
              </li>
            ))}
          </ul>
          <button className="home-cta home-cta-block" onClick={handleLoginClick} type="button" tabIndex={menuOpen ? 0 : -1}>
            Se connecter
          </button>
        </nav>
      </aside>

      <main>
        <section id="hero" className="home-hero">
          <div className="home-hero-inner">
            <p className="badge">Plateforme de formation</p>
            <h1>Apprenez. Enseignez. Evoluez.</h1>
            <p className="home-hero-copy">
              SkillHub reunit apprenants et formateurs autour d'un catalogue de formations
              suivies, notees et certifiees. Un seul endroit pour developper vos competences
              ou partager votre expertise.
            </p>
            <div className="home-hero-actions">
              <button className="home-cta" onClick={handleLoginClick} type="button">
                Commencer maintenant
              </button>
              <button
                className="home-cta home-cta-ghost"
                onClick={() => handleNavClick("features")}
                type="button"
              >
                Decouvrir les fonctionnalites
              </button>
            </div>
          </div>
        </section>

        <section id="features" className="home-section">
          <div className="home-section-head">
            <h2>Tout ce qu'il faut pour progresser</h2>
            <p>Une experience pensee pour les apprenants comme pour les formateurs.</p>
          </div>
          <div className="home-features-grid">
            <article className="home-feature-card">
              <span className="home-feature-icon" aria-hidden="true">[1]</span>
              <h3>Catalogue de formations</h3>
              <p>Recherchez par theme, niveau ou prix. Inscrivez-vous en un clic.</p>
            </article>
            <article className="home-feature-card">
              <span className="home-feature-icon" aria-hidden="true">[2]</span>
              <h3>Notation et avis</h3>
              <p>Notez les formations suivies et lisez les avis avant de vous inscrire.</p>
            </article>
            <article className="home-feature-card">
              <span className="home-feature-icon" aria-hidden="true">[3]</span>
              <h3>Vue formateur</h3>
              <p>Suivez vos apprenants inscrits et la progression de vos formations.</p>
            </article>
            <article className="home-feature-card">
              <span className="home-feature-icon" aria-hidden="true">[4]</span>
              <h3>Authentification SSO</h3>
              <p>Un seul compte pour acceder a tout l'ecosysteme SkillHub.</p>
            </article>
          </div>
        </section>

        <section id="how" className="home-section home-section-alt">
          <div className="home-section-head">
            <h2>Comment ca marche</h2>
            <p>Trois etapes pour rejoindre la communaute.</p>
          </div>
          <ol className="home-steps">
            <li>
              <span className="home-step-num">01</span>
              <div>
                <h3>Creez votre compte</h3>
                <p>Inscrivez-vous comme apprenant ou formateur en quelques secondes.</p>
              </div>
            </li>
            <li>
              <span className="home-step-num">02</span>
              <div>
                <h3>Choisissez votre voie</h3>
                <p>Parcourez le catalogue et inscrivez-vous aux formations qui vous interessent.</p>
              </div>
            </li>
            <li>
              <span className="home-step-num">03</span>
              <div>
                <h3>Apprenez et partagez</h3>
                <p>Suivez votre progression, notez les formations et echangez avec la communaute.</p>
              </div>
            </li>
          </ol>
        </section>

        <section id="faq" className="home-section">
          <div className="home-section-head">
            <h2>Questions frequentes</h2>
          </div>
          <div className="home-faq">
            <details>
              <summary>SkillHub est-il gratuit ?</summary>
              <p>L'inscription est libre. Certaines formations peuvent etre payantes selon le formateur.</p>
            </details>
            <details>
              <summary>Puis-je devenir formateur ?</summary>
              <p>Oui : choisissez le role formateur a l'inscription pour publier vos formations.</p>
            </details>
            <details>
              <summary>Comment recuperer mon mot de passe ?</summary>
              <p>Cliquez sur "Mot de passe oublie" depuis l'ecran de connexion.</p>
            </details>
          </div>
        </section>

        <section id="contact" className="home-section home-section-alt">
          <div className="home-section-head">
            <h2>Pret a vous lancer ?</h2>
            <p>Rejoignez la plateforme et commencez votre premiere formation aujourd'hui.</p>
          </div>
          <div className="home-cta-row">
            <button className="home-cta" onClick={handleLoginClick} type="button">
              Creer mon compte
            </button>
          </div>
        </section>
      </main>

      <footer className="home-footer">
        <p>(c) {new Date().getFullYear()} SkillHub - Plateforme de formation</p>
      </footer>
    </div>
  );
}

export default HomePage;
