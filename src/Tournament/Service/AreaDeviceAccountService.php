<?php

namespace Tournament\Service;

use Tournament\Repository\AreaDeviceAccountRepository;
use Tournament\Repository\TournamentRepository;

use Tournament\Model\Area\Area;
use Tournament\Model\AreaDevices\AreaDeviceSession;

use Base\Service\SessionService;
use Base\Service\SessionValidationIssue;
use SKleeschulte\Base32;


class AreaDeviceAccountService
{
   private ?Area $area = null;
   private ?AreaDeviceSession $device = null;

   protected const KEY_DEVICE_SESSION_ID = '_.areadevice.ID';

   public function __construct(
      private AreaDeviceAccountRepository $repo,
      private TournamentRepository $tournamentRepo,
      private SessionService $session,
      private int $session_expiry_h = 24,
      private \Random\Randomizer $rng = new \Random\Randomizer(),
   )
   {
   }

   /**
    * try to load session data
    */
   public function loadSession(): void
   {
      if( isset($this->device) || !$this->session->has(static::KEY_DEVICE_SESSION_ID) ) return;

      $this->device = $this->repo->getValidSessionById($this->session->get(static::KEY_DEVICE_SESSION_ID));

      if ($this->device)
      {
         $this->area = $this->tournamentRepo->getAreaById($this->device->area_id);
         $this->repo->updateSessionActivity($this->device->id, $this->session->id());
      }
      else
      {
         /* no valid session, drop the session identifier */
         $this->session->remove(static::KEY_DEVICE_SESSION_ID);
         throw new SessionValidationIssue('session expired');
      }
   }

   /**
    * create a new login token for the given area
    * this action will also invalidate any previously created login tokens
    * and it will log out any existing session for this area
    */
   public function createLoginCode(Area $area, int $code_len = 8, string $login_code_expiry = '1hours',): string
   {
      /* create a random text string via Crockford Base32 encoding to
       * get a humancopyable string - target length given via constructor parameter
       * each symbol carries 5 bits - we need ceil( ($code_len * 5 / 8) ) random bytes
       */
      $byte_count = ceil( (5*$code_len) / 8 );
      $seed = $this->rng->getBytes($byte_count);
      $code = substr( Base32::encodeByteStrToCrockford($seed), 0, $code_len );

      /* destroy any previous sessions or login tokens */
      $this->invalidateSession($area);
      $this->invalidateLoginCode($area);

      /* store this login code */
      /* we do not hash it, because it anyway only has a very limited lifetime,
       * during which we want to permanently display it to the user */
      $expiry = new \DateTime();
      $expiry->modify('+'.$login_code_expiry);
      $this->repo->storeLoginCode($area->id, $code, $expiry);

      return $code;
   }

   /**
    * explicitly invalidate any existing login tokens for this area
    */
   public function invalidateLoginCode(Area $area): void
   {
      $this->repo->invalidateLoginCode($area->id);
   }

   /**
    * explicitly invalidate any existing sessions for this area
    */
   public function invalidateSession(Area $area): void
   {
      $this->repo->invalidateSessionByAreaId($area->id);
   }

   /**
    * attempt to log in the current session as a device
    */
   public function login(string $login_code): bool
   {
      /* try to find the code in the corresponding table */
      $login = $this->repo->findValidLoginCode($login_code);
      if (!$login) return false;

      /* try to find the assigned area */
      $area_id = $login->area_id;
      $area = $this->tournamentRepo->getAreaById($area_id);
      if (!$area) return false;

      /* establish the expiry time for this session */
      $expiry = new \DateTime();
      $expiry->modify('+' . $this->session_expiry_h . ' hours');

      /* create the device session and mark the login code as used */
      $session = $this->repo->createSession($area->id, $expiry, $this->session->id());
      $this->session->set(static::KEY_DEVICE_SESSION_ID, $session->id);
      $this->repo->markLoginCodeUsed($login->id);

      /* report success */
      return true;
   }

   /**
    * log out the current device session we are in
    */
   public function logout(): void
   {
      if( $this->session->has(static::KEY_DEVICE_SESSION_ID) )
      {
         // mark the area device session as invalidated in the DB
         $this->repo->invalidateSession($this->session->get(static::KEY_DEVICE_SESSION_ID));
         // clear the current php session
         $this->session->clear();
      }
      else
      {
         // logout should never be called if this is no device session, so notify about this program flow issue
         throw new \LogicException('device logout called for non-device-area session');
      }
   }

   /**
    * return true if the current session is registered as area device
    */
   public function isDeviceAccount(): bool
   {
      return $this->getArea() !== null;
   }

   /**
    * get the assigned area of the current area device session
    */
   public function getArea(): ?Area
   {
      $this->loadSession();
      return $this->area;
   }

   /**
    * clean up login codes and sessions that are expired or used/invalidated
    */
   public function cleanUp(int $tournamentId): void
   {
      $this->repo->cleanLoginCodesByTournamentId($tournamentId);
      $this->repo->cleanSessionsByTournamentId($tournamentId);
   }
}