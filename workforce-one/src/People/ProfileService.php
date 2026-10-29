<?php
namespace WorkforceOne\People;

use WorkforceOne\People\Data\PeopleStore;

if (!defined('ABSPATH')) exit;

/** The profile picture of the signed-in employee (3.31.135, moved from trait-profile-account.php): the three writes. The controller keeps the uploads, the files and the audit rows. */
final class ProfileService
{
    /** @var PeopleStore */
    private $store;

    public function __construct(PeopleStore $store)
    {
        $this->store = $store;
    }

    /** @return int|false rows changed, false on a database error */
    public function avatar(int $employeeId, string $key, string $url)
    {
        return $this->store->setPicture($employeeId, 'avatar', $url, $key);
    }

    /** Back to the initials. @return int|false */
    public function reset(int $employeeId)
    {
        return $this->store->setPicture($employeeId, 'initials', '', null);
    }

    /** An uploaded photo. @return int|false */
    public function photo(int $employeeId, string $url)
    {
        return $this->store->setPicture($employeeId, 'photo', $url, null);
    }
}
