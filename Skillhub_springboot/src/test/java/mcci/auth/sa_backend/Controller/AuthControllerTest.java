package mcci.auth.sa_backend.Controller;

import mcci.auth.sa_backend.security.JwtService;
import mcci.auth.sa_backend.user.PasswordResetService;
import mcci.auth.sa_backend.user.UserAccount;
import mcci.auth.sa_backend.user.UserRepository;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.http.ResponseEntity;
import org.springframework.security.crypto.password.PasswordEncoder;

import java.util.Map;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

/**
 * Tests unitaires de {@link AuthController}.
 *
 * <p>Les collaborateurs sont simules : on verifie ici les codes de retour et le
 * contenu des reponses, notamment le fait qu'un echec d'authentification ne
 * divulgue jamais si le compte existe.</p>
 */
class AuthControllerTest {

    /** Secret de test (Base64), sans valeur en dehors de la suite de tests. */
    private static final String SECRET =
            "dGVzdC1vbmx5LXNraWxsaHViLXNzby1zaWduaW5nLWtleS0yMDI2LTMyLWJ5dGVz";

    private UserRepository users;
    private PasswordEncoder encoder;
    private PasswordResetService passwordResetService;
    private JwtService jwtService;
    private AuthController controller;

    @BeforeEach
    void setUp() {
        users = mock(UserRepository.class);
        encoder = mock(PasswordEncoder.class);
        passwordResetService = mock(PasswordResetService.class);
        jwtService = new JwtService(SECRET, "skillhub-sso", "skillhub-laravel", 3600);
        controller = new AuthController(jwtService, users, encoder, passwordResetService);
    }

    private UserAccount account() {
        return new UserAccount("apprenant@skillhub.test", "$2a$10$hash", "APPRENANT");
    }

    // ---------------------------------------------------------------- login

    @Test
    @DisplayName("Un login sans corps est refuse en 400")
    void loginWithoutBodyIsBadRequest() {
        assertEquals(400, controller.login(null).getStatusCode().value());
    }

    @Test
    @DisplayName("Un login sur un compte inconnu renvoie 401")
    void loginWithUnknownAccountIsUnauthorized() {
        when(users.findByUsername("inconnu@skillhub.test")).thenReturn(Optional.empty());

        ResponseEntity<?> response = controller.login(
                new AuthController.LoginRequest("inconnu@skillhub.test", "peu-importe"));

        assertEquals(401, response.getStatusCode().value());
    }

    @Test
    @DisplayName("Un mot de passe errone renvoie 401")
    void loginWithWrongPasswordIsUnauthorized() {
        when(users.findByUsername("apprenant@skillhub.test")).thenReturn(Optional.of(account()));
        when(encoder.matches(anyString(), anyString())).thenReturn(false);

        ResponseEntity<?> response = controller.login(
                new AuthController.LoginRequest("apprenant@skillhub.test", "mauvais"));

        assertEquals(401, response.getStatusCode().value());
    }

    @Test
    @DisplayName("Un login valide renvoie un JWT exploitable")
    void successfulLoginReturnsUsableToken() {
        when(users.findByUsername("apprenant@skillhub.test")).thenReturn(Optional.of(account()));
        when(encoder.matches(anyString(), anyString())).thenReturn(true);

        ResponseEntity<?> response = controller.login(
                new AuthController.LoginRequest("apprenant@skillhub.test", "un-mot-de-passe"));

        assertEquals(200, response.getStatusCode().value());

        AuthController.LoginResponse body =
                assertInstanceOf(AuthController.LoginResponse.class, response.getBody());

        assertEquals("Bearer", body.tokenType());
        assertEquals("APPRENANT", body.role());
        assertEquals(3600, body.expiresIn());
        assertEquals(
                "apprenant@skillhub.test",
                jwtService.parse(body.accessToken()).getPayload().getSubject());
    }

    @Test
    @DisplayName("Compte inconnu et mot de passe errone renvoient le meme message")
    void loginFailuresAreIndistinguishable() {
        when(users.findByUsername("inconnu@skillhub.test")).thenReturn(Optional.empty());
        when(users.findByUsername("apprenant@skillhub.test")).thenReturn(Optional.of(account()));
        when(encoder.matches(anyString(), anyString())).thenReturn(false);

        Object unknown = controller.login(
                new AuthController.LoginRequest("inconnu@skillhub.test", "x")).getBody();
        Object wrongPassword = controller.login(
                new AuthController.LoginRequest("apprenant@skillhub.test", "x")).getBody();

        assertEquals(unknown, wrongPassword);
    }

    // ------------------------------------------------------------- validate

    @Test
    @DisplayName("Une validation sans en-tete Authorization renvoie 401")
    void validateWithoutHeaderIsUnauthorized() {
        assertEquals(401, controller.validate(null).getStatusCode().value());
    }

    @Test
    @DisplayName("Une validation avec un schema autre que Bearer renvoie 401")
    void validateWithNonBearerSchemeIsUnauthorized() {
        assertEquals(401, controller.validate("Basic dXNlcjpwYXNz").getStatusCode().value());
    }

    @Test
    @DisplayName("Un JWT valide est confirme avec ses claims publiques")
    void validateAcceptsGenuineToken() {
        String token = jwtService.generateToken("apprenant@skillhub.test", "APPRENANT");

        ResponseEntity<?> response = controller.validate("Bearer " + token);

        assertEquals(200, response.getStatusCode().value());

        @SuppressWarnings("unchecked")
        Map<String, Object> body = (Map<String, Object>) response.getBody();

        assertNotNull(body);
        assertEquals(Boolean.TRUE, body.get("valid"));
        assertEquals("apprenant@skillhub.test", body.get("subject"));
        assertEquals("APPRENANT", body.get("role"));
        assertEquals("skillhub-sso", body.get("issuer"));
    }

    @Test
    @DisplayName("Un JWT falsifie est rejete en 401")
    void validateRejectsForgedToken() {
        ResponseEntity<?> response = controller.validate("Bearer forge.pas.un.jwt");

        assertEquals(401, response.getStatusCode().value());

        @SuppressWarnings("unchecked")
        Map<String, Object> body = (Map<String, Object>) response.getBody();

        assertNotNull(body);
        assertEquals(Boolean.FALSE, body.get("valid"));
    }

    // ------------------------------------------------------- mot de passe

    @Test
    @DisplayName("Une demande de reinitialisation sans username est refusee")
    void forgotPasswordRequiresUsername() {
        assertEquals(400, controller.forgotPassword(null).getStatusCode().value());
        assertEquals(400, controller.forgotPassword(
                new AuthController.ForgotPasswordRequest("   ")).getStatusCode().value());
    }

    @Test
    @DisplayName("La reponse de reinitialisation ne revele pas l'existence du compte")
    void forgotPasswordDoesNotLeakAccountExistence() {
        when(passwordResetService.createResetToken("inconnu@skillhub.test"))
                .thenReturn(Optional.empty());

        ResponseEntity<?> response = controller.forgotPassword(
                new AuthController.ForgotPasswordRequest("inconnu@skillhub.test"));

        assertEquals(200, response.getStatusCode().value());

        @SuppressWarnings("unchecked")
        Map<String, Object> body = (Map<String, Object>) response.getBody();

        assertNotNull(body);
        assertTrue(body.containsKey("message"));
        assertFalse(body.containsKey("resetToken"));
    }

    @Test
    @DisplayName("Un token de reinitialisation invalide renvoie 400")
    void resetPasswordWithInvalidTokenIsBadRequest() {
        when(passwordResetService.resetPassword("expire", "nouveau")).thenReturn(false);

        ResponseEntity<?> response = controller.resetPassword(
                new AuthController.ResetPasswordRequest("expire", "nouveau"));

        assertEquals(400, response.getStatusCode().value());
    }

    @Test
    @DisplayName("Un token de reinitialisation valide renvoie 200")
    void resetPasswordWithValidTokenSucceeds() {
        when(passwordResetService.resetPassword("valide", "nouveau")).thenReturn(true);

        ResponseEntity<?> response = controller.resetPassword(
                new AuthController.ResetPasswordRequest("valide", "nouveau"));

        assertEquals(200, response.getStatusCode().value());
    }

    @Test
    @DisplayName("Une reinitialisation sans corps est refusee en 400")
    void resetPasswordWithoutBodyIsBadRequest() {
        assertEquals(400, controller.resetPassword(null).getStatusCode().value());
    }
}
