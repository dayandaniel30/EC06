package mcci.auth.sa_backend.security;

import io.jsonwebtoken.Claims;
import io.jsonwebtoken.ExpiredJwtException;
import io.jsonwebtoken.JwtException;
import io.jsonwebtoken.Jws;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

/**
 * Tests unitaires de {@link JwtService}.
 *
 * <p>Ces tests couvrent le contrat de securite du SSO : un jeton n'est accepte
 * que si sa signature, son emetteur, son audience et sa date d'expiration sont
 * tous valides. Aucun contexte Spring n'est demarre, le service est instancie
 * directement avec sa configuration.</p>
 */
class JwtServiceTest {

    /** Secret de test (Base64), distinct de tout secret de production. */
    private static final String SECRET =
            "dGVzdC1vbmx5LXNraWxsaHViLXNzby1zaWduaW5nLWtleS0yMDI2LTMyLWJ5dGVz";

    /** Second secret, utilise pour simuler un jeton forge par un tiers. */
    private static final String OTHER_SECRET =
            "YW5vdGhlci10ZXN0LWtleS1za2lsbGh1Yi1zc28tMjAyNi0zMi1ieXRlcy1vaw==";

    private static final String ISSUER = "skillhub-sso";
    private static final String AUDIENCE = "skillhub-laravel";

    private JwtService jwtService(long expirationSeconds) {
        return new JwtService(SECRET, ISSUER, AUDIENCE, expirationSeconds);
    }

    @Test
    @DisplayName("Un jeton emis puis relu conserve son sujet et son role")
    void generatedTokenCarriesSubjectAndRole() {
        JwtService service = jwtService(3600);

        String token = service.generateToken("apprenant@skillhub.test", "APPRENANT");
        Jws<Claims> parsed = service.parse(token);
        Claims claims = parsed.getPayload();

        assertEquals("apprenant@skillhub.test", claims.getSubject());
        assertEquals("APPRENANT", claims.get("role"));
        assertEquals(ISSUER, claims.getIssuer());
        assertTrue(claims.getAudience().contains(AUDIENCE));
    }

    @Test
    @DisplayName("La duree de vie configuree est exposee telle quelle")
    void expirationSecondsIsExposed() {
        assertEquals(1800, jwtService(1800).getExpirationSeconds());
    }

    @Test
    @DisplayName("Un jeton signe avec une autre cle est rejete")
    void tokenSignedWithAnotherKeyIsRejected() {
        JwtService attacker = new JwtService(OTHER_SECRET, ISSUER, AUDIENCE, 3600);
        JwtService service = jwtService(3600);

        String forged = attacker.generateToken("apprenant@skillhub.test", "ADMIN");

        assertThrows(JwtException.class, () -> service.parse(forged));
    }

    @Test
    @DisplayName("Un jeton emis par un autre issuer est rejete")
    void tokenFromAnotherIssuerIsRejected() {
        JwtService foreign = new JwtService(SECRET, "autre-sso", AUDIENCE, 3600);
        JwtService service = jwtService(3600);

        String token = foreign.generateToken("apprenant@skillhub.test", "APPRENANT");

        assertThrows(JwtException.class, () -> service.parse(token));
    }

    @Test
    @DisplayName("Un jeton destine a une autre audience est rejete")
    void tokenForAnotherAudienceIsRejected() {
        JwtService foreign = new JwtService(SECRET, ISSUER, "autre-client", 3600);
        JwtService service = jwtService(3600);

        String token = foreign.generateToken("apprenant@skillhub.test", "APPRENANT");

        assertThrows(JwtException.class, () -> service.parse(token));
    }

    @Test
    @DisplayName("Un jeton expire est rejete")
    void expiredTokenIsRejected() {
        // Duree de vie negative : le jeton naît deja expire.
        JwtService service = jwtService(-60);

        String token = service.generateToken("apprenant@skillhub.test", "APPRENANT");

        assertThrows(ExpiredJwtException.class, () -> service.parse(token));
    }

    @Test
    @DisplayName("Une chaine qui n'est pas un JWT est rejetee")
    void malformedTokenIsRejected() {
        JwtService service = jwtService(3600);

        assertThrows(JwtException.class, () -> service.parse("ceci-n-est-pas-un-jwt"));
    }
}
