<?php

namespace Base\Service;

use Base\Repository\UserRepository;
use Base\Model\User;
use Base\Service\PasswordHasher;


class PasswordResetService
{
   private const TOKEN_EXPIRATION_TIME_MINUTES = 30;
   private const TOKEN_LENGTH = 32;
   private const MAX_TOKEN_COUNT = 3;

   public function __construct(private UserRepository $userRepository, private PasswordHasher $passwordHasher)
   {
   }

   /**
    * create and store a reset token
    */
   public function createResetToken(string $email): ?string
   {
      // find the user
      $user = $this->userRepository->findByEmail($email);
      if (!$user)
      {
         // User doesn't exist, return
         return null;
      }

      // rate limitation of created tokens:
      if (count($this->userRepository->findResetTokensForUser($user->id)) >= self::MAX_TOKEN_COUNT)
      {
         return null;
      }

      // all fine, create and store a new reset token
      $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
      $tokenHash = password_hash($token, PASSWORD_DEFAULT);
      if( $this->userRepository->storePasswordResetToken($user->id, $tokenHash, self::TOKEN_EXPIRATION_TIME_MINUTES) )
      {
         return $token;
      }
      else
      {
         return null;
      }
   }

   /**
    * Validate a reset token and return the associated user
    * Deletes the validated token from the database
    * @param string $email
    * @param string $token
    * @return User|null
    */
   public function validateToken(string $email, string $token): ?User
   {
      $user = $this->userRepository->findByEmail($email);
      if( !$user )
      {
         // User doesn't exist, return null
         return null;
      }

      $tokenList = $this->userRepository->findResetTokensForUser($user->id);
      foreach ($tokenList as $tokenData)
      {
         // check if the token is valid
         if (password_verify($token, $tokenData['token_hash']))
         {
            // Token is valid, delete it to prevent re-use
            $this->userRepository->deleteResetToken($tokenData['token_hash']);
            // return the found user as the token was found to be valid
            return $user;
         }
      }

      // provided reset token is not valid (anymore)
      return null;
   }

   /**
    * actually store a new password
    */
   public function storePassword(int $userId, string $newPassword): bool
   {
      $hashed_password = $this->passwordHasher->hash($newPassword);
      $this->userRepository->updateUserPassword($userId, $hashed_password);

      // Make sure any remaining reset tokens are invalidated
      $this->userRepository->deleteResetTokensForUser($userId);

      return true;
   }

   /**
    * clean up expired reset tokens
    */
   public function cleanupResetTokens(): bool
   {
      return $this->userRepository->cleanupResetTokens();
   }
}
