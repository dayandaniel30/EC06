package mcci.auth.sa_backend;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;

/**
 * Point d'entree du microservice d'authentification Skillhub SSO.
 *
 * <p>L'application expose des endpoints REST sous {@code /auth} et delivre
 * des JWT signes consommes par les autres briques du systeme (notamment
 * l'API Laravel et les frontends React).</p>
 */
@SpringBootApplication
public class SaBackendApplication {

    /**
     * Demarrage standard Spring Boot.
     *
     * @param args arguments en ligne de commande
     */
    public static void main(String[] args) {
        SpringApplication.run(SaBackendApplication.class, args);
    }
}
