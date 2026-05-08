package mcci.auth.sa_backend.user;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.boot.CommandLineRunner;
import org.springframework.security.crypto.password.PasswordEncoder;
import org.springframework.stereotype.Component;

/**
 * Initialise un utilisateur de demonstration au premier demarrage.
 *
 * <p>Si la table {@code users} est vide, un compte est cree a partir des
 * proprietes {@code security.seed.*}. Le mot de passe est hashe avec BCrypt
 * avant d'etre persiste.</p>
 */
@Component
public class UserSeeder implements CommandLineRunner {

    private static final Logger log = LoggerFactory.getLogger(UserSeeder.class);

    private final UserRepository users;
    private final PasswordEncoder encoder;
    private final String seedUsername;
    private final String seedPassword;
    private final String seedRole;

    public UserSeeder(
            UserRepository users,
            PasswordEncoder encoder,
            @Value("${security.seed.username}") String seedUsername,
            @Value("${security.seed.password}") String seedPassword,
            @Value("${security.seed.role}") String seedRole
    ) {
        this.users = users;
        this.encoder = encoder;
        this.seedUsername = seedUsername;
        this.seedPassword = seedPassword;
        this.seedRole = seedRole;
    }

    /**
     * Cree le compte de demo au demarrage si la table est vide.
     *
     * @param args arguments transmis par Spring Boot (ignores)
     */
    @Override
    public void run(String... args) {
        if (users.count() > 0) {
            log.info("UserSeeder: users table not empty ({} rows) - skipping seed", users.count());
            return;
        }
        users.save(new UserAccount(seedUsername, encoder.encode(seedPassword), seedRole));
        log.info("UserSeeder: seeded demo user '{}'", seedUsername);
    }
}
