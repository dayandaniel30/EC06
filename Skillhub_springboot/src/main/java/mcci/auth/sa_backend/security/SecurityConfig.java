package mcci.auth.sa_backend.security;

import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import org.springframework.security.config.annotation.web.builders.HttpSecurity;
import org.springframework.security.config.annotation.web.configurers.AbstractHttpConfigurer;
import org.springframework.security.config.http.SessionCreationPolicy;
import org.springframework.security.crypto.bcrypt.BCryptPasswordEncoder;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.security.web.SecurityFilterChain;

/**
 * Configuration Spring Security du SSO.
 *
 * <p>Le service expose des endpoints publics ({@code /auth/**}, {@code /actuator/health})
 * et fonctionne en mode strictement stateless : aucune session HTTP n'est creee,
 * la securite repose uniquement sur les JWT.</p>
 */
@Configuration
public class SecurityConfig {

    /**
     * Encoder BCrypt utilise pour hasher les mots de passe a la creation du compte
     * et lors de la reinitialisation.
     *
     * @return un {@link BCryptPasswordEncoder} avec parametres par defaut
     */
    @Bean
    public PasswordEncoder passwordEncoder() {
        return new BCryptPasswordEncoder();
    }

    /**
     * Definit la chaine de filtres de securite : pas de session, CSRF/CORS desactives,
     * formulaire de login natif et basic auth desactives, endpoints {@code /auth/**} publics.
     *
     * @param http builder fourni par Spring Security
     * @return la chaine de filtres construite
     * @throws Exception si la configuration est invalide
     */
    @Bean
    public SecurityFilterChain filterChain(HttpSecurity http) throws Exception {
        http
                .csrf(AbstractHttpConfigurer::disable)
                .cors(AbstractHttpConfigurer::disable)
                .sessionManagement(sm -> sm.sessionCreationPolicy(SessionCreationPolicy.STATELESS))
                .authorizeHttpRequests(auth -> auth
                        .requestMatchers("/auth/**", "/actuator/health").permitAll()
                        .anyRequest().authenticated()
                )
                .httpBasic(AbstractHttpConfigurer::disable)
                .formLogin(AbstractHttpConfigurer::disable);
        return http.build();
    }
}
