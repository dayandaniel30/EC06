package mcci.auth.sa_backend.user;

import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.security.SecureRandom;
import java.time.Duration;
import java.time.Instant;
import java.util.Base64;
import java.util.Optional;

/**
 * Service responsable du cycle de vie des tokens de reinitialisation de mot de passe.
 *
 * <p>Le service genere un token opaque ({@code 256 bits} encodes en base64 URL-safe),
 * le persiste avec une date d'expiration courte sur le compte cible, puis valide
 * et consomme ce token lors de la reinitialisation effective.</p>
 *
 * <p>Note : dans cette demonstration le token est retourne par l'API,
 * en production il devrait uniquement etre transmis a l'utilisateur final
 * par email ou SMS.</p>
 */
@Service
public class PasswordResetService {

    /** Duree de validite par defaut d'un token de reinitialisation. */
    public static final Duration TOKEN_TTL = Duration.ofMinutes(30);

    private static final SecureRandom RANDOM = new SecureRandom();
    private static final Base64.Encoder URL_ENCODER = Base64.getUrlEncoder().withoutPadding();

    private final UserRepository users;
    private final PasswordEncoder encoder;

    public PasswordResetService(UserRepository users, PasswordEncoder encoder) {
        this.users = users;
        this.encoder = encoder;
    }

    /**
     * Genere un nouveau token de reinitialisation pour l'utilisateur cible et le persiste.
     *
     * <p>Si aucun utilisateur ne correspond, la methode renvoie {@link Optional#empty()}
     * pour permettre au controleur de masquer l'existence du compte.</p>
     *
     * @param username identifiant fonctionnel du compte
     * @return token opaque emis, encapsule dans un {@link Optional}
     */
    @Transactional
    public Optional<String> createResetToken(String username) {
        Optional<UserAccount> found = users.findByUsername(username);
        if (found.isEmpty()) {
            return Optional.empty();
        }
        String token = generateToken();
        UserAccount user = found.get();
        user.setPasswordResetToken(token, Instant.now().plus(TOKEN_TTL));
        users.save(user);
        return Optional.of(token);
    }

    /**
     * Valide un token et applique le nouveau mot de passe.
     *
     * @param token       token opaque precedemment emis par {@link #createResetToken(String)}
     * @param newPassword nouveau mot de passe en clair (sera hashe via BCrypt)
     * @return {@code true} si la reinitialisation a aboutie, {@code false} si le token
     *         est inconnu ou expire
     */
    @Transactional
    public boolean resetPassword(String token, String newPassword) {
        if (token == null || token.isBlank() || newPassword == null || newPassword.isBlank()) {
            return false;
        }
        Optional<UserAccount> found = users.findByPasswordResetToken(token);
        if (found.isEmpty()) {
            return false;
        }
        UserAccount user = found.get();
        Instant expiresAt = user.getPasswordResetExpiresAt();
        if (expiresAt == null || expiresAt.isBefore(Instant.now())) {
            user.clearPasswordResetToken();
            users.save(user);
            return false;
        }
        user.setPasswordHash(encoder.encode(newPassword));
        user.clearPasswordResetToken();
        users.save(user);
        return true;
    }

    private static String generateToken() {
        byte[] bytes = new byte[32];
        RANDOM.nextBytes(bytes);
        return URL_ENCODER.encodeToString(bytes);
    }
}
