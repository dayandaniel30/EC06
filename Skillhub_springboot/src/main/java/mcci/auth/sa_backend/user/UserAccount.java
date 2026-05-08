package mcci.auth.sa_backend.user;

import jakarta.persistence.Column;
import jakarta.persistence.Entity;
import jakarta.persistence.GeneratedValue;
import jakarta.persistence.GenerationType;
import jakarta.persistence.Id;
import jakarta.persistence.Table;

import java.time.Instant;

/**
 * Entite JPA representant un compte utilisateur du SSO Skillhub.
 *
 * <p>Le compte stocke l'identifiant de connexion, le hash BCrypt du mot de passe,
 * le role applicatif (par exemple {@code APPRENANT} ou {@code FORMATEUR})
 * ainsi que les informations associees au mecanisme de reinitialisation de
 * mot de passe (token et date d'expiration).</p>
 */
@Entity
@Table(name = "users")
public class UserAccount {

    /** Identifiant technique auto-genere. */
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;

    /** Identifiant fonctionnel unique (typiquement une adresse email). */
    @Column(nullable = false, unique = true)
    private String username;

    /** Hash BCrypt du mot de passe. Jamais stocke en clair. */
    @Column(nullable = false)
    private String passwordHash;

    /** Role applicatif (APPRENANT, FORMATEUR, ADMIN, ...). */
    @Column(nullable = false)
    private String role;

    /** Token opaque utilise pour autoriser une reinitialisation de mot de passe. */
    @Column(name = "password_reset_token")
    private String passwordResetToken;

    /** Instant d'expiration du token de reinitialisation. */
    @Column(name = "password_reset_expires_at")
    private Instant passwordResetExpiresAt;

    protected UserAccount() {}

    /**
     * Cree un nouveau compte utilisateur.
     *
     * @param username      identifiant fonctionnel unique
     * @param passwordHash  hash BCrypt du mot de passe
     * @param role          role applicatif
     */
    public UserAccount(String username, String passwordHash, String role) {
        this.username = username;
        this.passwordHash = passwordHash;
        this.role = role;
    }

    public Long getId() { return id; }
    public String getUsername() { return username; }
    public String getPasswordHash() { return passwordHash; }
    public String getRole() { return role; }

    public String getPasswordResetToken() { return passwordResetToken; }
    public Instant getPasswordResetExpiresAt() { return passwordResetExpiresAt; }

    /**
     * Met a jour le hash du mot de passe.
     *
     * @param passwordHash hash BCrypt du nouveau mot de passe
     */
    public void setPasswordHash(String passwordHash) {
        this.passwordHash = passwordHash;
    }

    /**
     * Stocke un token de reinitialisation et sa date d'expiration.
     *
     * @param token     token opaque (peut etre {@code null} pour invalider)
     * @param expiresAt instant d'expiration
     */
    public void setPasswordResetToken(String token, Instant expiresAt) {
        this.passwordResetToken = token;
        this.passwordResetExpiresAt = expiresAt;
    }

    /** Invalide le token de reinitialisation courant. */
    public void clearPasswordResetToken() {
        this.passwordResetToken = null;
        this.passwordResetExpiresAt = null;
    }
}
