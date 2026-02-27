<?php

namespace Tournament\Model\User;

use \Respect\Validation\Validator as v;

class User extends \Base\Model\User implements \Tournament\Model\Base\DbItem
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      ?int $id,
      string $email,
      public string $display_name,
      public readonly \DateTime $created_at,
      public ?\DateTime $last_login = null,
      public RoleCollection $roles = new RoleCollection(),
      string $password_hash = '',
      bool $is_active = true,
      int $session_version = 1,
   )
   {
      parent::__construct($id, $email, $password_hash, $is_active, $session_version);
   }

   public function hasRole(Role $role)
   {
      return $this->roles->contains($role);
   }

   protected static function validationRules(): array
   {
      return [
         'email'        => v::email(),
         'display_name' => v::stringType()->length(min:3, max:127),
         'is_active'    => v::boolVal(),
         'roles'        => v::arrayType()->unique()->each(v::in(array_map(fn($e) => $e->value, Role::cases())))
      ];
   }

   public function updateFromArray(array $data): void
   {
      if(isset($data['email']))        $this->email = $data['email'];
      if(isset($data['display_name'])) $this->display_name = $data['display_name'];
      if(isset($data['is_active']))    $this->is_active = $data['is_active'];
      if(isset($data['roles']))        $this->roles = RoleCollection::new($data['roles']);
   }
}
