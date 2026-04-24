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

@Service
public class JwtService {

    private final SecretKey signingKey;
    private final String issuer;
    private final String audience;
    private final long expirationSeconds;

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

    public Jws<Claims> parse(String token) {
        return Jwts.parser()
                .verifyWith(signingKey)
                .requireIssuer(issuer)
                .requireAudience(audience)
                .build()
                .parseSignedClaims(token);
    }

    public long getExpirationSeconds() {
        return expirationSeconds;
    }
}
