<?php

namespace Tournament\Controller;

use Base\Service\AuthService;
use Base\Service\PasswordResetService;
use Base\Service\MailService;
use Base\Service\SessionService;
use Base\Service\DbUpdateService;

use Tournament\Repository\UserRepository;

use Tournament\Model\User\Role;
use Tournament\Model\User\User;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

class AuthController
{

   public function __construct(
      private Twig $twig,
      private AuthService $authService,
      private PasswordResetService $passwordResetService,
      private MailService $mailService,
      private UserRepository $userRepository,
      private SessionService $session,
      private DbUpdateService $dbUpdService
   )
   {
   }

   public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      return $this->twig->render($response, 'auth/login.twig');
   }

   public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      $data = (array)$request->getParsedBody();
      $email = trim($data['email'] ?? '');
      $password = $data['password'];
      $error = null;

      if ($this->authService->login($email, $password))
      {
         /* prepare redirection of user after a successful login */
         $redirect = $this->session->get('redirect_after_login') ?? RouteContext::fromRequest($request)->getRouteParser()->urlFor('home');
         $this->session->remove('redirect_after_login');

         /* query the user instance to execute any login-hooks */
         $user = $this->authService->getCurrentUser();

         /* Register login */
         $this->userRepository->registerLogin($user);

         /* a successful login from anyone is a nice hook to clean up any expired reset tokens, so let's do it here */
         $this->passwordResetService->cleanupResetTokens();

         /* a successful login of an admin is a nice hook to check for needed DB migration */
         if( ($user instanceof \Tournament\Model\User\User) && $user->hasRole(Role::ADMIN) )
         {
            if( $this->dbUpdService->updateNeeded() )
            {
               /* perform the database update */
               $db_update_output = $this->dbUpdService->update();

               /* after migration happened, show the output as an intermediate step */
               return $this->twig->render($response, 'special_pages/db_migration_message.twig', [
                  'redirect' => $redirect,
                  'message'  => $db_update_output
               ]);
            }
         }

         return $response
            ->withHeader('Location', $redirect)
            ->withStatus(302);
      }
      else
      {
         /* only in case a disabled user tried to log in give this specific feedback
          * check for disabled user is performed AFTER the password was checked
          *
          * for any other login issue, DO NOT give detailled feedback, so an attacker cannot
          * determine existing usernames
          */
         if( $this->authService->getLoginIssue() === AuthService::USER_DISABLED )
         {
            $error = "Nutzer ist deaktiviert";
         }
      }

      return $this->twig->render($response, 'auth/login.twig', [
         'error' => $error ?? 'Ungültiger Benutzername oder Passwort',
         'email' => $email,
      ])->withStatus(403);
   }

   public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      $this->authService->logout();
      return $response
         ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()->urlFor('home'))
         ->withStatus(302);
   }

   public function showForgotPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      return $this->twig->render($response, 'auth/password_forgot.twig');
   }

   public function requestPasswordReset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      $data = (array)$request->getParsedBody();
      $email = trim($data['email'] ?? '');

      $token = $this->passwordResetService->createResetToken($email);

      if( $token !== null )
      {
         $uri = $request->getUri();

         // get the url for the pw_reset route, with token and email as GET parameters
         $resetUrl = RouteContext::fromRequest($request)->getRouteParser()
            ->fullUrlFor($uri, 'pw_reset', [], ['email' => $email, 'token' => $token]);

         // build an application name from our uri
         $app_name = $uri->getHost() . \config::$BASE_PATH ?? '';

         /** @var User $user load the user to retrieve the username */
         $user = $this->userRepository->findUser(['email' => $email]);

         // prepare the context data for mail rendering
         $context = [
            'username'  => $user->display_name,
            'reset_url' => $resetUrl,
            'app_name'  => $app_name,
         ];

         // load the mail template
         $tmpl = $this->twig->getEnvironment()->load('emails/password_reset_link.twig');

         // prepare the mail content by rendering the template
         $subject = trim($tmpl->renderBlock('subject', $context));
         $bodyHtml = $tmpl->renderBlock('body_html', $context);

         // send the email
         $this->mailService->send($email, $subject, $bodyHtml);
      }
      else
      {
         // do not return any error info if no valid token could be created (which happens if too many tokens are already in place)
         // otherwise, an attacker could use this info to determine whether the username/mail does even exist in the system.
      }

      return $this->twig->render($response, 'auth/reset_link_confirm.twig');
   }

   public function showResetPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      // read email/token from query parameters
      $queryParams = $request->getQueryParams();
      $email = $queryParams['email'] ?? null;
      $token = $queryParams['token'] ?? null;

      $user = $this->passwordResetService->validateToken($email, $token);
      if( $user !== null )
      {
         // valid token, mark ongoing reset flow in the session
         $this->session->set('password_reset_user', $user->id);
      }
      else if( $this->session->has('password_reset_user') )
      {
         // user is already in the middle of a password reset flow, just continue
         // he/she probably clicked the reset link again
      }
      else
      {
         return $this->twig->render($response, 'auth/password_forgot.twig', [
            'error' => 'Link ist abgelaufen! Bitte beantrage einen neuen Link.'
         ])->withStatus(400);
      }

      return $this->twig->render($response, 'auth/password_reset.twig');
   }

   public function resetPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      if( !$this->session->has('password_reset_user') )
      {
         return $this->twig->render($response, 'auth/login.twig', [
            'error' => 'Ungültige Session!'
         ])->withStatus(400);
      }

      // read in all data
      $data = (array)$request->getParsedBody();
      $password = $data['password'] ?? '';
      $confirmPassword = $data['confirm_password'] ?? '';

      // check if passwords match, also they should not be empty (duh!)
      if ($password !== $confirmPassword || empty($password))
      {
         return $this->twig->render($response, 'auth/password_reset.twig', [
            'error' => 'Passwörter stimmen nicht überein',
         ])->withStatus(400);
      }

      // all fine, store the new password
      if ($this->passwordResetService->storePassword($this->session->get('password_reset_user'), $password))
      {
         // unset the session variable
         $this->session->remove('password_reset_user');
         // show confirmation window
         return $this->twig->render($response, 'auth/password_reset_success.twig');
      }

      // password storage failed for some reason, return to login with error
      return $this->twig->render($response, 'auth/login.twig', [
         'error' => 'Password reset fehlgeschlagen, bitte versuchen Sie es erneut.'
      ])->withStatus(400);
   }

}
