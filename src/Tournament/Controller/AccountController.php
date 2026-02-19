<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

use Tournament\Repository\UserRepository;
use Tournament\Model\User\User;

use Base\Service\PasswordHasher;
use Base\Service\PrgService;

class AccountController
{
   public function __construct(
      private Twig $twig,
      private UserRepository $userRepository,
      private PasswordHasher $hasher,
      private PrgService $prgService,
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
      $errors = $user->validateArray($data, required: ['display_name']);

      if( !$errors )
      {
         /* check if password change is requested, and process it */
         if (!empty($data['current_password']) && !empty($data['new_password']))
         {
            if( !$this->hasher->verify($data['current_password'], $user->password_hash) )
            {
               $errors['password'] = 'Aktuelles Passwort ist falsch.';
            }
            else if( $data['new_password'] !== ($data['new_password_repeat'] ?? '') )
            {
               $errors['password'] = 'Die neuen Passwörter stimmen nicht überein.';
            }
            else
            {
               // hash and store new password
               $new_password = $this->hasher->hash($data['new_password']);
               $this->userRepository->updateUserPassword($user->id, $new_password);
               // invalidate all other sessions for this user
               $this->userRepository->destroySessionsForUser($user->id, true);
            }
         }
      }

      /* take over stored data if no issue */
      if (!$errors)
      {
         $user->updateFromArray($data);
         $this->userRepository->saveUser($user);
         return $this->prgService->redirect($request, $response, 'user_account', []);
      }
      else
      {
         /* do not return any entered passwords */
         unset($data['current_password'], $data['new_password'], $data['new_password_repeat']);

         return $this->twig->render($response, 'user/account.twig', [
            'user'   => $user,
            'errors' => $errors,
            'prev'   => $data,
         ]);
      }
   }
}
