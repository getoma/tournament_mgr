<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Views\Twig;

use Base\Service\MailService;
use Base\Service\PasswordResetService;
use Base\Service\PrgService;

use Tournament\Exception\EntityNotFoundException;
use Tournament\Model\User\Role;
use Tournament\Model\User\RoleCollection;
use Tournament\Repository\UserRepository;
use Tournament\Model\User\User;
use Tournament\Policy\TournamentPolicy;

class UserManagementController
{
   public function __construct(
      private Twig $twig,
      private UserRepository $repo,
      private PasswordResetService $passwordResetService,
      private MailService $mailService,
      private PrgService $prgService,
   )
   {
   }

   public function showCreateUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      return $this->twig->render($response,'user/user_create.twig');
   }

   public function createUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      /* parse the input data */
      $data   = $request->getParsedBody();
      $errors = User::validateArray($data, required: ['email']);

      if(!$errors)
      {
         if( $this->repo->isMailUsed($data['email']) )
         {
            $errors['email'] = 'E-Mail existiert bereits als User';
         }
      }

      if(!$errors)
      {
         // create and save new user
         $user = new User(
            id: null,
            email: $data['email'],
            display_name: $data['email'],
            created_at: new \DateTime()
            );
         $this->repo->saveUser($user);

         // forward to user details
         return $this->prgService->redirect($request, $response, 'users.show', ['userId' => $user->id], 'created');
      }
      else
      {
         // return the form with errors
         return $this->twig->render($response, 'user/user_create.twig', [
            'prev' => $data,
            'errors' => $errors
         ]);
      }
   }

   public function listUsers(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      return $this->twig->render($response, 'user/user_management.twig', [
         'users' => $this->repo->getAllUsers()
      ]);
   }

   public function showUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      $user = isset($args['userId'])? $this->repo->findUser(['id' => $args['userId']]) : null;
      if(!isset($user)) throw new EntityNotFoundException($request, 'user not found');

      return $this->twig->render($response, 'user/user_details.twig', [
         'user'   => $user,
         'roles'  => Role::cases(),
      ]);
   }

   public function sendNewUserMail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      /** @var User $user */
      $user = isset($args['userId']) ? $this->repo->findUser(['id' => $args['userId']]) : null;
      if (!isset($user)) throw new EntityNotFoundException($request, 'user not found');

      // only allow to send a "new user mail" as long as no password is set
      if ($user->password_hash)
      {
         $errors = [ 'error' => 'Nutzer hat bereits ein Passwort gesetzt.'];
      }
      else
      {
         $token = $this->passwordResetService->createResetToken($user->email);

         if (!$token)
         {
            $errors = ['error' => 'Es konnte kein neues Reset-Token erstellt werden.'];
         }
         else
         {
            $uri = $request->getUri();

            // get the url for the password reset route, with token and email as GET parameters
            $resetUrl = RouteContext::fromRequest($request)->getRouteParser()
               ->fullUrlFor($uri, 'auth.password.reset.form', [], ['email' => $user->email, 'token' => $token]);

            // build an application name from our uri
            $app_name = $uri->getHost() . \config::$BASE_PATH ?? '';

            // prepare the context data for mail rendering
            $context = [
               'app_name'  => $app_name,
               'username'  => $user->display_name,
               'reset_url' => $resetUrl,
            ];

            // load the mail template
            $tmpl = $this->twig->getEnvironment()->load('emails/new_user_mail.twig');

            // prepare the mail content by rendering the template
            $subject = trim($tmpl->renderBlock('subject', $context));
            $bodyHtml = $tmpl->renderBlock('body_html', $context);

            // send the email
            $this->mailService->send($user->email, $subject, $bodyHtml);

            // redirect-to-GET
            return $this->prgService->redirect($request, $response, 'users.show', $args, 'mail_sent');
         }
      }

      return $this->twig->render($response, 'user/user_details.twig', [
         'user'    => $user,
         'roles'   => Role::cases(),
         'errors'  => $errors,
      ]);
   }

   public function updateUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      /** @var User $user */
      $user = isset($args['userId']) ? $this->repo->findUser(['id' => $args['userId']]) : null;
      if (!isset($user)) throw new EntityNotFoundException($request, 'user not found');

      /** @var TournamentPolicy $policy */
      $policy = $request->getAttribute('policy');
      if( !isset($policy) ) throw new \LogicException('policy not found - is the policy middleware installed?');

      /* parse the input data */
      $data   = $request->getParsedBody();
      /* default-initialize checkbox fields, as those are missing if not checked at all */
      $data['roles']     ??= [];
      $data['is_active'] ??= false;
      $errors = $user->validateArray($data, ['roles', 'is_active']);

      if( !$errors ) // if input valid, check policy
      {
         $newRoles = RoleCollection::new($data['roles']);
         if( !$policy->canModifyUser($user, $newRoles) )
         {
            $errors['policy'] = 'Nutzeränderung nicht erlaubt';
         }
      }

      if( $errors ) // if either input invalid or policy blocks, return with error
      {
         return $this->twig->render($response, 'user/user_details.twig', [
            'user'   => $user,
            'roles'  => Role::cases(),
            'errors' => $errors,
            'prev'   => $data
         ]);
      }

      // all fine, save updates and return normal formular
      $was_active = $user->is_active;
      $user->updateFromArray($data);
      $this->repo->saveUser($user);

      // if user was disabled, invalidate all his current sessions
      if( $was_active && !$user->is_active ) $this->repo->rotateUserSession($user->id);

      // done, redirect-to-GET
      return $this->prgService->redirect($request, $response, 'users.show', $args, 'updated');
   }

   public function deleteUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      /** @var User $user */
      $user = isset($args['userId']) ? $this->repo->findUser(['id' => $args['userId']]) : null;
      if (!isset($user)) throw new EntityNotFoundException($request, 'user not found');

      $this->repo->deleteUser($user->id);

      return $this->prgService->redirect($request, $response, 'users.index', $args, 'user_deleted');
   }

}