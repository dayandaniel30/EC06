package mcci.auth.sa_backend.user;

import org.springframework.data.jpa.repository.JpaRepository;

import java.util.Optional;

/**
 * Repository Spring Data JPA pour {@link UserAccount}.
 *
 * <p>Expose les operations de lecture/ecriture par defaut de
 * {@link JpaRepository} ainsi que des recherches utilisees par le
 * processus d'authentification et de reinitialisation de mot de passe.</p>
 */
public interface UserRepository extends JpaRepository<UserAccount, Long> {

    /**
     * Recherche un utilisateur par son identifiant fonctionnel.
     *
     * @param username identifiant fonctionnel (email)
     * @return l'utilisateur correspondant, ou {@link Optional#empty()} si aucun
     */
    Optional<UserAccount> findByUsername(String username);

    /**
     * Recherche un utilisateur par son token de reinitialisation de mot de passe.
     *
     * @param token token opaque emis par {@code /auth/forgot-password}
     * @return l'utilisateur portant ce token, ou {@link Optional#empty()} si aucun
     */
    Optional<UserAccount> findByPasswordResetToken(String token);
}
