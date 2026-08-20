package mcci.auth.sa_backend.user;

import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.DisplayName;
import org.junit.jupiter.api.Test;
import org.springframework.security.crypto.password.PasswordEncoder;

import java.time.Instant;
import java.util.Optional;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;
import static org.mockito.ArgumentMatchers.any;
import static org.mockito.ArgumentMatchers.anyString;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.never;
import static org.mockito.Mockito.verify;
import static org.mockito.Mockito.when;

/**
 * Tests unitaires de {@link PasswordResetService}.
 *
 * <p>Le repository et l'encodeur sont simules : ces tests verifient les regles
 * du cycle de vie du token (emission, expiration, consommation unique) sans
 * dependre d'une base de donnees.</p>
 */
class PasswordResetServiceTest {

    private UserRepository users;
    private PasswordEncoder encoder;
    private PasswordResetService service;

    @BeforeEach
    void setUp() {
        users = mock(UserRepository.class);
        encoder = mock(PasswordEncoder.class);
        service = new PasswordResetService(users, encoder);
    }

    private UserAccount account() {
        return new UserAccount("apprenant@skillhub.test", "$2a$10$hash", "APPRENANT");
    }

    @Test
    @DisplayName("Aucun token n'est emis pour un compte inconnu")
    void noTokenForUnknownAccount() {
        when(users.findByUsername("inconnu@skillhub.test")).thenReturn(Optional.empty());

        Optional<String> token = service.createResetToken("inconnu@skillhub.test");

        assertTrue(token.isEmpty());
        verify(users, never()).save(any());
    }

    @Test
    @DisplayName("Un token est emis et persiste pour un compte existant")
    void tokenIsIssuedAndPersisted() {
        UserAccount user = account();
        when(users.findByUsername("apprenant@skillhub.test")).thenReturn(Optional.of(user));

        Optional<String> token = service.createResetToken("apprenant@skillhub.test");

        assertTrue(token.isPresent());
        assertEquals(token.get(), user.getPasswordResetToken());
        assertTrue(user.getPasswordResetExpiresAt().isAfter(Instant.now()));
        verify(users).save(user);
    }

    @Test
    @DisplayName("Une reinitialisation sans token ou sans mot de passe est refusee")
    void blankArgumentsAreRejected() {
        assertFalse(service.resetPassword(null, "nouveau"));
        assertFalse(service.resetPassword("   ", "nouveau"));
        assertFalse(service.resetPassword("token", null));
        assertFalse(service.resetPassword("token", "  "));

        verify(users, never()).save(any());
    }

    @Test
    @DisplayName("Un token inconnu ne reinitialise rien")
    void unknownTokenIsRejected() {
        when(users.findByPasswordResetToken("absent")).thenReturn(Optional.empty());

        assertFalse(service.resetPassword("absent", "nouveauMotDePasse"));
        verify(users, never()).save(any());
    }

    @Test
    @DisplayName("Un token expire est refuse et immediatement invalide")
    void expiredTokenIsRejectedAndCleared() {
        UserAccount user = account();
        user.setPasswordResetToken("expire", Instant.now().minusSeconds(60));
        when(users.findByPasswordResetToken("expire")).thenReturn(Optional.of(user));

        assertFalse(service.resetPassword("expire", "nouveauMotDePasse"));

        assertNull(user.getPasswordResetToken());
        assertNull(user.getPasswordResetExpiresAt());
        verify(users).save(user);
        verify(encoder, never()).encode(anyString());
    }

    @Test
    @DisplayName("Un token valide applique le nouveau hash et se consomme")
    void validTokenResetsPasswordAndIsConsumed() {
        UserAccount user = account();
        user.setPasswordResetToken("valide", Instant.now().plusSeconds(600));
        when(users.findByPasswordResetToken("valide")).thenReturn(Optional.of(user));
        when(encoder.encode("nouveauMotDePasse")).thenReturn("$2a$10$nouveauHash");

        assertTrue(service.resetPassword("valide", "nouveauMotDePasse"));

        assertEquals("$2a$10$nouveauHash", user.getPasswordHash());
        assertNull(user.getPasswordResetToken());
        verify(users).save(user);
    }
}
