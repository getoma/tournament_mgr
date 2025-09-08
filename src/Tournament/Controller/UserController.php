<?php

namespace Tournament\Controller;

use Base\Service\MailService;
use Base\Service\PasswordHasher;
use Base\Repository\UserRepository;
use Base\Model\User;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class UserController
{

   public function __construct(
      private Twig $twig,
      private MailService $mailService,
      private UserRepository $userRepository,
      private PasswordHasher $hasher
   )
   {
   }

   public function showAccount(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      return $this->twig->render($response, 'user/account.twig', [
         'user' => $request->getAttribute('current_user')
      ]);
   }

   public function updateAccount(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      /** @var User $user */
      $user = $request->getAttribute('current_user');
      $data = (array)$request->getParsedBody();
      $user->display_name = trim($data['display_name'] ?? $user->display_name);
      $user->admin = (bool)($data['admin'] ?? $user->admin);
      $this->userRepository->updateUser($user->id, $user->display_name, $user->admin);

      /* check if password change is requested, and process it */
      $password_error = null;
      $password_msg = null;
      if (!empty($data['current_password']) && !empty($data['new_password']))
      {
         if( !$this->hasher->verify($data['current_password'], $user->password_hash) )
         {
            $password_error = 'Aktuelles Passwort ist falsch.';
         }
         else if( $data['new_password'] !== ($data['new_password_repeat'] ?? '') )
         {
            $password_error = 'Die neuen Passwörter stimmen nicht überein.';
         }
         else
         {
            // hash and store new password
            $new_password = $this->hasher->hash($data['new_password']);
            $this->userRepository->updateUserPassword($user->id, $new_password);
            // invalidate all other sessions for this user
            $this->userRepository->destroySessionsForUser($user->id, true);
            // return success message
            $password_msg = 'Passwort erfolgreich geändert.';
         }
      }

      /* do not return any entered passwords */
      unset($data['current_password'], $data['new_password'], $data['new_password_repeat']);

      return $this->twig->render($response, 'user/account.twig', [
         'user'  => $request->getAttribute('current_user'),
         'password_error' => $password_error,
         'prev'  => $data,
         'password_msg' => $password_msg
      ]);
   }

}
