<?php
/**
 * MCP API key. The plain token is shown once at creation; only its SHA-256
 * hash is stored. Every call made with the key runs with the rights of the
 * linked employee, limited further by the key's scopes and shops.
 *
 * @author    PrestaPro
 * @license   AFL-3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PpMcpApiKey extends ObjectModel
{
    const TOKEN_PREFIX = 'ppmcp_';

    /** @var string */
    public $name;
    /** @var string */
    public $token_hash;
    /** @var string */
    public $token_hint;
    /** @var int */
    public $id_employee;
    /** @var string comma separated: read,write,code */
    public $scopes = 'read';
    /** @var string comma separated shop ids, empty = all shops */
    public $shops = '';
    /** @var bool */
    public $active = true;
    /** @var string|null */
    public $date_expire;
    /** @var string|null */
    public $last_used;
    /** @var string|null OAuth client_id when the key was issued through OAuth */
    public $oauth_client;
    /** @var string|null SHA-256 of the current OAuth refresh token */
    public $refresh_hash;
    /** @var string|null OAuth access token expiry (the key itself may live longer) */
    public $token_expire;
    /** @var string */
    public $last_ip = '';
    /** @var string */
    public $date_add;
    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'ppmcp_key',
        'primary' => 'id_ppmcp_key',
        'fields' => [
            'name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 64],
            'token_hash' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 64],
            'token_hint' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 16],
            'id_employee' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'scopes' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64],
            'shops' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'date_expire' => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'allow_null' => true],
            'last_used' => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'allow_null' => true],
            'last_ip' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 45],
            'oauth_client' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64, 'allow_null' => true],
            'refresh_hash' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64, 'allow_null' => true],
            'token_expire' => ['type' => self::TYPE_DATE, 'validate' => 'isDateFormat', 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    public function add($autoDate = true, $nullValues = true)
    {
        $this->normaliseDates();

        return parent::add($autoDate, $nullValues);
    }

    public function update($nullValues = true)
    {
        $this->normaliseDates();

        return parent::update($nullValues);
    }

    private function normaliseDates()
    {
        foreach (['date_expire', 'last_used', 'token_expire'] as $f) {
            if (empty($this->{$f}) || strpos((string) $this->{$f}, '0000-00-00') === 0) {
                $this->{$f} = null;
            }
        }
    }

    public static function generateToken(): string
    {
        return self::TOKEN_PREFIX . bin2hex(random_bytes(24));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Sets hash + hint from a fresh plain token.
     */
    public function setToken(string $token): void
    {
        $this->token_hash = self::hashToken($token);
        $this->token_hint = substr($token, 0, 12);
    }

    public static function findByToken(string $token): ?self
    {
        $token = trim($token);
        if (strpos($token, self::TOKEN_PREFIX) !== 0 || strlen($token) < 20) {
            return null;
        }
        $hash = self::hashToken($token);
        $id = (int) Db::getInstance()->getValue(
            'SELECT `id_ppmcp_key` FROM `' . _DB_PREFIX_ . 'ppmcp_key` WHERE `token_hash` = \'' . pSQL($hash) . '\'',
            false
        );
        if (!$id) {
            return null;
        }
        $key = new self($id);
        if (!Validate::isLoadedObject($key) || !hash_equals((string) $key->token_hash, $hash)) {
            return null;
        }

        return $key;
    }

    public function isUsable(): bool
    {
        if (!$this->active) {
            return false;
        }
        if ($this->date_expire && $this->date_expire !== '0000-00-00 00:00:00' && strtotime($this->date_expire) < time()) {
            return false;
        }

        return !$this->isAccessTokenExpired();
    }

    /**
     * OAuth keys: the access token is short-lived and renewed with the refresh token.
     */
    public function isAccessTokenExpired(): bool
    {
        return $this->token_expire && $this->token_expire !== '0000-00-00 00:00:00' && strtotime($this->token_expire) < time();
    }

    /**
     * @return string[]
     */
    public function getScopes(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->scopes))));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->getScopes(), true);
    }

    /**
     * @return int[] empty = all shops
     */
    public function getShopIds(): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $this->shops))));
    }

    public function allowsShop(int $idShop): bool
    {
        $ids = $this->getShopIds();

        return !$ids || in_array($idShop, $ids, true);
    }

    public function touch(string $ip): void
    {
        Db::getInstance()->update(
            'ppmcp_key',
            ['last_used' => date('Y-m-d H:i:s'), 'last_ip' => pSQL(substr($ip, 0, 45))],
            '`id_ppmcp_key` = ' . (int) $this->id
        );
    }
}
