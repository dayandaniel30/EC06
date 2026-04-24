package mcci.auth.sa_backend.Controller;

import jakarta.validation.constraints.NotBlank;
import mcci.auth.sa_backend.security.JwtService;
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

import java.util.Map;
import java.util.Optional;

@RestController
@RequestMapping(path = "/auth")
public class AuthController {

    private final JwtService jwtService;
    private final UserRepository users;
    private final PasswordEncoder encoder;

    public AuthController(JwtService jwtService, UserRepository users, PasswordEncoder encoder) {
        this.jwtService = jwtService;
        this.users = users;
        this.encoder = encoder;
    }

    public record LoginRequest(@NotBlank String username, @NotBlank String password) {}

    public record LoginResponse(String accessToken, String tokenType, long expiresIn, String role) {}

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
}
