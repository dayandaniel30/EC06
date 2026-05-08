package mcci.auth.sa_backend.security;

import io.jsonwebtoken.Claims;
import io.jsonwebtoken.Jws;
import io.jsonwebtoken.Jwts;
import io.jsonwebtoken.io.Decoders;
import io.jsonwebtoken.security.Keys;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;

import javax.crypto.SecretKey;
import java.util.Date;
import java.util.Map;

/**
 * Service de generation et de verification des JWT signes par le SSO Skillhub.
 *
 * <p>Les jetons sont signes en HS256 a partir d'un secret encode en Base64
 * dans la propriete {@code security.jwt.secret}. L'emetteur, l'audience et
 * la duree de vie sont configurables via les proprietes {@code security.jwt.*}.</p>
 */
@Service
public class JwtService {

    private final SecretKey signingKey;
    private final String issuer;
    private final String audience;
    private final long expirationSeconds;

    /**
     * Construit le service en lisant la configuration JWT.
     *
     * @param secret             secret partage encode en Base64
     * @param issuer             valeur de la claim {@code iss}
     * @param audience           valeur de la claim {@code aud}
     * @param expirationSeconds  duree de vie des jetons en secondes
     */
    public JwtService(
            @Value("${security.jwt.secret}") String secret,
            @Value("${security.jwt.issuer}") String issuer,
            @Value("${security.jwt.audience}") String audience,
            @Value("${security.jwt.expiration-seconds}") long expirationSeconds
    ) {
        byte[] keyBytes = Decoders.BASE64.decode(secret);
        this.signingKey = Keys.hmacShaKeyFor(keyBytes);
        this.issuer = issuer;
        this.audience = audience;
        this.expirationSeconds = expirationSeconds;
    }

    /**
     * Genere un JWT signe contenant le sujet et le role.
     *
     * @param subject sujet du jeton (typiquement l'identifiant utilisateur)
     * @param role    role applicatif a inclure dans la claim {@code role}
     * @return le JWT compact prêt a etre transmis au client
     */
    public String generateToken(String subject, String role) {
        Date now = new Date();
        Date exp = new Date(now.getTime() + expirationSeconds * 1000L);
        return Jwts.builder()
                .issuer(issuer)
                .audience().add(audience).and()
                .subject(subject)
                .claims(Map.of("role", role))
                .issuedAt(now)
                .expiration(exp)
                .signWith(signingKey)
                .compact();
    }

    /**
     * Verifie la signature d'un JWT et controle l'emetteur et l'audience.
     *
     * @param token JWT compact a verifier
     * @return les claims signees
     * @throws io.jsonwebtoken.JwtException si la signature ou les claims requises sont invalides
     */
    public Jws<Claims> parse(String token) {
        return Jwts.parser()
                .verifyWith(signingKey)
                .requireIssuer(issuer)
                .requireAudience(audience)
                .build()
                .parseSignedClaims(token);
    }

    /**
     * @return la duree de vie configuree des jetons, en secondes
     */
    public long getExpirationSeconds() {
        return expirationSeconds;
    }
}
