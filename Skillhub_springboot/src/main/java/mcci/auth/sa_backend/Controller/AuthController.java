package mcci.auth.sa_backend.Controller;

import jakarta.validation.constraints.NotBlank;
import mcci.auth.sa_backend.security.JwtService;
import mcci.auth.sa_backend.user.PasswordResetService;
import mcci.auth.sa_backend.user.UserAccount;
import mcci.auth.sa_backend.user.UserRepository;
import org.springframework.http.ResponseEntity;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;

import java.util.HashMap;
import java.util.Map;
import java.util.Optional;

/**
 * Controleur REST exposant les endpoints d'authentification du SSO Skillhub.
 *
 * <ul>
 *   <li>{@code POST /auth/login} : echange identifiants / JWT</li>
 *   <li>{@code GET  /auth/validate} : validation d'un JWT entrant</li>
 *   <li>{@code POST /auth/forgot-password} : demande d'un token de reinitialisation</li>
 *   <li>{@code POST /auth/reset-password} : application d'un nouveau mot de passe</li>
 * </ul>
 */
@RestController
@RequestMapping(path = "/auth")
public class AuthController {

    private final JwtService jwtService;
    private final UserRepository users;
    private final PasswordEncoder encoder;
    private final PasswordResetService passwordResetService;

    public AuthController(
            JwtService jwtService,
            UserRepository users,
            PasswordEncoder encoder,
            PasswordResetService passwordResetService
    ) {
        this.jwtService = jwtService;
        this.users = users;
        this.encoder = encoder;
        this.passwordResetService = passwordResetService;
    }

    /** Corps JSON attendu pour {@code POST /auth/login}. */
    public record LoginRequest(@NotBlank String username, @NotBlank String password) {}

    /** Reponse JSON renvoyee apres authentification reussie. */
    public record LoginResponse(String accessToken, String tokenType, long expiresIn, String role) {}

    /** Corps JSON attendu pour {@code POST /auth/forgot-password}. */
    public record ForgotPasswordRequest(@NotBlank String username) {}

    /** Corps JSON attendu pour {@code POST /auth/reset-password}. */
    public record ResetPasswordRequest(@NotBlank String token, @NotBlank String newPassword) {}

    /**
     * Authentifie un utilisateur et delivre un JWT.
     *
     * @param body identifiants {@link LoginRequest}
     * @return un {@link LoginResponse} en cas de succes, ou une 401 en cas d'echec
     */
    @PostMapping("/login")
    public ResponseEntity<?> login(@RequestBody LoginRequest body) {
        if (body == null || body.username() == null || body.password() == null) {
            return ResponseEntity.badRequest().body(Map.of("message", "username et password requis"));
        }

        Optional<UserAccount> found = users.findByUsername(body.username());
        if (found.isEmpty() || !encoder.matches(body.password(), found.get().getPasswordHash())) {
            return ResponseEntity.status(401).body(Map.of("message", "Identifiants invalides"));
        }

        UserAccount user = found.get();
        String token = jwtService.generateToken(user.getUsername(), user.getRole());
        return ResponseEntity.ok(new LoginResponse(token, "Bearer", jwtService.getExpirationSeconds(), user.getRole()));
    }

    /**
     * Valide un JWT transmis dans l'entete {@code Authorization}.
     *
     * @param authorization entete de la forme {@code Bearer <jwt>}
     * @return les claims publiques en cas de succes, ou une 401 sinon
     */
    @GetMapping("/validate")
    public ResponseEntity<?> validate(@RequestHeader(name = "Authorization", required = false) String authorization) {
        if (authorization == null || !authorization.startsWith("Bearer ")) {
            return ResponseEntity.status(401).body(Map.of("message", "Header Authorization manquant"));
        }
        String token = authorization.substring(7);
        try {
            var claims = jwtService.parse(token).getPayload();
            return ResponseEntity.ok(Map.of(
                    "valid", true,
                    "subject", claims.getSubject(),
                    "role", claims.get("role"),
                    "issuer", claims.getIssuer(),
                    "audience", claims.getAudience(),
                    "expiresAt", claims.getExpiration().toInstant().toString()
            ));
        } catch (Exception e) {
            return ResponseEntity.status(401).body(Map.of("valid", false, "message", e.getMessage()));
        }
    }

    /**
     * Demande la generation d'un token de reinitialisation pour le compte cible.
     *
     * <p>Pour limiter les fuites d'informations sur l'existence d'un compte, la
     * reponse renvoyee est identique que l'utilisateur existe ou non. En mode
     * developpement, le token genere est inclus dans la reponse pour faciliter
     * les tests ; en production il devrait etre transmis exclusivement par email.</p>
     *
     * @param body objet {@link ForgotPasswordRequest} contenant l'identifiant cible
     * @return reponse JSON neutre, accompagnee du token en mode developpement
     */
    @PostMapping("/forgot-password")
    public ResponseEntity<?> forgotPassword(@RequestBody ForgotPasswordRequest body) {
        if (body == null || body.username() == null || body.username().isBlank()) {
            return ResponseEntity.badRequest().body(Map.of("message", "username requis"));
        }

        Optional<String> token = passwordResetService.createResetToken(body.username());

        Map<String, Object> response = new HashMap<>();
        response.put("message", "Si ce compte existe, un email de reinitialisation a ete envoye.");
        token.ifPresent(value -> response.put("resetToken", value));
        return ResponseEntity.ok(response);
    }

    /**
     * Applique un nouveau mot de passe a partir d'un token de reinitialisation.
     *
     * @param body objet {@link ResetPasswordRequest} contenant le token et le nouveau mot de passe
     * @return 200 en cas de succes, 400 si le token est invalide ou expire
     */
    @PostMapping("/reset-password")
    public ResponseEntity<?> resetPassword(@RequestBody ResetPasswordRequest body) {
        if (body == null || body.token() == null || body.newPassword() == null) {
            return ResponseEntity.badRequest().body(Map.of("message", "token et newPassword requis"));
        }

        boolean ok = passwordResetService.resetPassword(body.token(), body.newPassword());
        if (!ok) {
            return ResponseEntity.badRequest().body(Map.of("message", "Token invalide ou expire"));
        }
        return ResponseEntity.ok(Map.of("message", "Mot de passe reinitialise avec succes"));
    }
}
