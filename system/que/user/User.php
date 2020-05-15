<?php
/**
 * Created by PhpStorm.
 * User: Wisdom Emenike
 * Date: 2/1/2019
 * Time: 10:36 PM
 */

namespace que\user;


use ArrayAccess;
use que\common\exception\PreviousException;
use que\common\exception\QueRuntimeException;
use que\database\model\interfaces\Model;
use que\session\Session;
use que\utility\client\Browser;
use que\utility\client\IP;

class User extends State implements ArrayAccess
{
    /**
     * @var User
     */
    private static User $instance;

    /**
     * @var array
     */
    private static array $state;

    /**
     * @var object
     */
    private static object $user;

    /**
     * @var array
     */
    private static array $model = [];

    /**
     * @var array
     */
    private static $database_config;

    /**
     * @var array
     */
    private static $session_config;

    /**
     * @var array
     */
    private static $cache_config;

    /**
     * User constructor.
     */
    protected function __construct()
    {
        parent::__construct();
        self::$database_config = config('database');
        self::$session_config = config('session');
        self::$cache_config = config('cache');
    }

    private function __clone()
    {
        // TODO: Implement __clone() method.
    }

    private function __wakeup()
    {
        // TODO: Implement __wakeup() method.
    }

    /**
     * @return User
     */
    public static function getInstance(): User
    {

        if (!self::isLoggedIn())
            throw new QueRuntimeException("Trying to get a user instance when you're not logged in.",
                "User Error", E_USER_ERROR, 0, PreviousException::getInstance(1));

        if (!isset(self::$instance)) {

            self::$state = self::get_state();

            if (!self::is_equal_state()) {
                self::logout(vsprintf("Your connection state got corrupted due to access from (IP::%s) using " .
                    "%s browser at %s with %s system. Please re-login or change password to avoid possible hijack", [
                    self::getLastIP(),
                    self::getLastBrowser()['browser'] ?? 'unknown',
                    date("h:i a l, jS M Y", self::getLastSeen()) ?: 'unknown',
                    self::getLastBrowser()['platform'] ?? 'unknown'
                ]));
            }

            if (self::$session_config['timeout'] === true && (APP_TIME >= (self::getLastSeen() + self::$session_config['timeout_time'])))
                self::logout(vsprintf("System session expired for security reasons. Please re-login (IP::%s)", [self::getLastIP()]));

            self::$user = &self::$state['data'];

            if (self::$session_config['regeneration'] === true && ((APP_TIME - self::getLastSeen()) >= self::$session_config['regeneration_time']))
                self::regenerate();

            self::updateState();

            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * @param string $model
     * @return Model
     */
    public function getModel(string $model = null): Model
    {
        $model = model(($modelKey = $model ?? config("database.default.model")));

        if ($model === null) throw new QueRuntimeException(
            "No database model was found with the key '{$modelKey}', check your database configuration to fix this issue.",
            "Que Runtime Error", E_USER_ERROR, HTTP_INTERNAL_SERVER_ERROR, PreviousException::getInstance(1));

        if (!($implements = class_implements($model)) || !in_array(Model::class, $implements)) throw new QueRuntimeException(
            "The specified model ({$model}) with key '{$modelKey}' does not implement the Que database model interface.",
            "Que Runtime Error", E_USER_ERROR, HTTP_INTERNAL_SERVER_ERROR, PreviousException::getInstance(1));

        if (!isset(self::$model[$modelKey]))
            self::$model[$modelKey] = new $model(self::$user,
                self::$database_config['tables']['user']['name'] ?? 'users',
                self::$database_config['tables']['user']['primary_key'] ?? 'id'
            );
        return self::$model[$modelKey];
    }

    /**
     * @param $key
     * @param null $default
     * @return mixed|null
     */
    public function getValue($key, $default = null)
    {
        return self::$user->{$key} ?? $default;
    }

    /**
     * @param $key
     * @param int $default
     * @return int
     */
    public function getInt($key, int $default = 0): int
    {
        return (int) $this->getValue($key, $default);
    }

    /**
     * @param $key
     * @param float $default
     * @return float
     */
    public function getFloat($key, float $default = 0.0): float
    {
        return (float) $this->getValue($key, $default);
    }

    /**
     * @return object
     */
    public function &getUserObject(): object
    {
        return self::$user;
    }

    /**
     * @return array
     */
    public function getUserArray(): array
    {
        return object_to_array(self::$user);
    }

    /**
     * @param array $columns
     * @return bool
     */
    public function update(array $columns): bool
    {
        if (!self::isLoggedIn() || empty($columns)) return false;

        $columnsToUpdate = [];
        foreach ($columns as $key => $value) {
            if (object_key_exists($key, self::$user) && self::$user->{$key} != $value) {
                $columnsToUpdate[$key] = $value;
            }
        }

        if (empty($columnsToUpdate)) return false;

        $primaryKey = self::$database_config['tables']['user']['primary_key'] ?? 'id';

        $update = db()->update((self::$database_config['tables']['user']['name'] ?? 'users'), $columnsToUpdate, [
            'AND' => [
                $primaryKey => $this->getValue($primaryKey, 0)
            ]
        ]);

        if ($status = $update->isSuccessful()) {
            foreach ($columnsToUpdate as $key => $value)
                self::$user->{$key} = $value;

            self::updateState();
        }

        return $status;
    }

    /**
     * @param $key
     * @param $value
     * @return bool
     */
    public function updateInMemory($key, $value): bool {
        if (!isset(self::$user->{$key}) || self::$user->{$key} == $value) return false;
        self::$user->{$key} = $value;
        self::updateState();
        return true;
    }

    /**
     * Update user state
     */
    private static function updateState()
    {
        self::login(self::$user);
    }

    /**
     * @return mixed
     */
    private static function getLastSeen()
    {
        return self::$state['time'] ?? APP_TIME;
    }

    /**
     * @return mixed
     */
    private static function getLastIP()
    {
        return self::$state['ip'] ?? 'unknown';
    }

    /**
     * @return mixed
     */
    private static function getLastBrowser()
    {
        return self::$state['browser'] ?? [];
    }

    private static function regenerate()
    {

        $memcached = $redis = $quekip = null;

        if ((self::$cache_config['memcached']['enable'] ?? false) === true)
            $memcached = Session::getInstance()->getMemcached();

        if ((self::$cache_config['redis']['enable'] ?? false) === true)
            $redis = Session::getInstance()->getRedis();

        if ((self::$cache_config['memcached']['enable'] ?? false) !== true &&
            (self::$cache_config['redis']['enable'] ?? false) !== true)
            $quekip = Session::getInstance()->getQueKip();

        if (!@session_regenerate_id(true)) { // change session ID for the current session and invalidate old session ID
            // Give it some time to regenerate session ID
            sleep(1);
            session_regenerate_id(true);
        }

        $primaryKey = (self::$database_config['tables']['user']['primary_key'] ?? 'id');

        $user = db()->find((self::$database_config['tables']['user']['name'] ?? 'users'), $primaryKey,
            self::$state['data']->{$primaryKey} ?? 0);

        if ($user->isSuccessful()) {
            $userData = $user->getQueryResponseArray(0);
            foreach ($userData as $key => $value)
                self::$user->{$key} = $value;
        }

        if (!is_null($memcached))
            $memcached->reset_session_id(Session::getSessionID());

        if (!is_null($redis))
            $redis->reset_session_id(Session::getSessionID());

        if (!is_null($quekip))
            $quekip->reset_session_id(Session::getSessionID());

        self::updateState();
    }


    /**
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        return self::has_active_state();
    }

    /**
     * Log in user
     * @param object $user
     */
    public static function login(object $user)
    {
        self::set_state([
            'uid' => Session::getSessionID(),
            'data' => $user,
            'time' => APP_TIME,
            'ip' => IP::real(),
            'browser' => Browser::browserInfo()
        ]);
    }

    /**
     * Log out user
     * @param string $redirect_to
     * @param null $message
     */
    public static function logout($message = null, string $redirect_to = null)
    {
        $redirect_to = $redirect_to ?? (current_route()->isRequireLogIn() ? current_route()->getLoginUrl() : current_uri());
        $message = $message ?? sprintf("Good bye, see you soon. Log-out successful (IP::%s)", self::getLastIP());
        self::flush();
        if (current_route()->getType() != 'web') throw new QueRuntimeException($message, "User Error",
            E_USER_ERROR, 0, PreviousException::getInstance());
        else http()->redirect()->setUrl($redirect_to ?? '/')->setHeader($message, SUCCESS)->initiate();
    }

    /**
     * Whether a offset exists
     * @link https://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset <p>
     * An offset to check for.
     * </p>
     * @return boolean true on success or false on failure.
     * </p>
     * <p>
     * The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists($offset)
    {
        // TODO: Implement offsetExists() method.
        return object_key_exists($offset, self::$user);
    }

    /**
     * Offset to retrieve
     * @link https://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset <p>
     * The offset to retrieve.
     * </p>
     * @return mixed Can return all value types.
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        // TODO: Implement offsetGet() method.
        return self::$user->{$offset} ?? null;
    }

    /**
     * Offset to set
     * @link https://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset <p>
     * The offset to assign the value to.
     * </p>
     * @param mixed $value <p>
     * The value to set.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        // TODO: Implement offsetSet() method.
        self::$user->{$offset} = $value;

    }

    /**
     * Offset to unset
     * @link https://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset <p>
     * The offset to unset.
     * </p>
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        // TODO: Implement offsetUnset() method.
        unset(self::$user->{$offset});
    }
}