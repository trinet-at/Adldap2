<?php

namespace Adldap\Classes;

use Adldap\Collections\AdldapGroupCollection;
use Adldap\Objects\Group;
use Adldap\Adldap;

/**
 * Ldap Group management.
 *
 * Class AdldapGroups
 */
class AdldapGroups extends AdldapBase
{
    /**
     * The groups object category string.
     *
     * @var string
     */
    public $objectCategory = 'group';

    /**
     * Returns a complete list of all groups in AD.
     *
     * @param bool   $includeDescription Whether to return a description
     * @param string $search             Search parameters
     * @param bool   $sorted             Whether to sort the results
     *
     * @return array|bool
     */
    public function all($includeDescription = false, $search = '*', $sorted = true)
    {
        return $this->search(null, $includeDescription, $search, $sorted);
    }

    /**
     * Finds a group and returns it's information.
     *
     * @param string $groupName The group name
     * @param array  $fields    The group fields to retrieve
     *
     * @return array|bool
     */
    public function find($groupName, $fields = [])
    {
        return $this->adldap->search()
            ->select($fields)
            ->where('objectCategory', '=', $this->objectCategory)
            ->where('anr', '=', $groupName)
            ->first();
    }

    /**
     * Group Information. Returns an array of raw information about a group.
     * The group name is case sensitive.
     *
     * @param string $groupName The group name to retrieve info about
     * @param array  $fields    Fields to retrieve
     *
     * @return array|bool
     */
    public function info($groupName, $fields = [])
    {
        return $this->find($groupName, $fields);
    }

    /**
     * Returns a complete list of the groups in AD based on a SAM Account Type.
     *
     * @param int   $sAMAaccountType The account type to return
     * @param array $select          The fields you want to retrieve for each
     * @param bool  $sorted          Whether to sort the results
     *
     * @return array|bool
     */
    public function search($sAMAaccountType = Adldap::ADLDAP_SECURITY_GLOBAL_GROUP, $select = [], $sorted = true)
    {
        $this->adldap->utilities()->validateLdapIsBound();

        $search = $this->adldap->search()
            ->select($select)
            ->where('objectCategory', '=', 'group');

        if ($sAMAaccountType !== null) {
            $search->where('samaccounttype', '=', $sAMAaccountType);
        }

        if ($sorted) {
            $search->sortBy('samaccountname', 'asc');
        }

        return $search->get();
    }

    /**
     * Obtain the group's distinguished name based on their group ID.
     *
     * @param string $groupName
     *
     * @return string|bool
     */
    public function dn($groupName)
    {
        $group = $this->info($groupName);

        if (is_array($group) && array_key_exists('dn', $group)) {
            return $group['dn'];
        }

        return false;
    }

    /**
     * Add a group to a group.
     *
     * @param string $parent The parent group name
     * @param string $child  The child group name
     *
     * @return bool
     */
    public function addGroup($parent, $child)
    {
        // Find the parent group's dn
        $parentDn = $this->dn($parent);

        $childDn = $this->dn($child);

        if($parentDn && $childDn)
        {
            $add['member'] = $childDn;

            // Add the child to the parent group and return the result
            return $this->connection->modAdd($parentDn, $add);
        }

        return false;
    }

    /**
     * Add a user to a group.
     *
     * @param string $groupName  The group to add the user to
     * @param string $username  The user to add to the group
     * @param bool   $isGUID Is the username passed a GUID or a samAccountName
     *
     * @return bool
     */
    public function addUser($groupName, $username, $isGUID = false)
    {
        // Adding a user is a bit fiddly, we need to get the full DN of the user
        // and add it using the full DN of the group
        $groupDn = $this->dn($groupName);

        $userDn = $this->adldap->user()->dn($username);

        if($groupDn && $userDn)
        {
            $add['member'] = $userDn;

            return $this->connection->modAdd($groupDn, $add);
        }

        return false;
    }

    /**
     * Add a contact to a group.
     *
     * @param string $groupName The group to add the contact to
     * @param string $contactDn The DN of the contact to add
     *
     * @return bool
     */
    public function addContact($groupName, $contactDn)
    {
        // Find the group's dn
        $groupDn = $this->dn($groupName);

        if($groupDn && $contactDn)
        {
            $add['member'] = $contactDn;

            return $this->connection->modAdd($groupDn, $add);
        }

        return false;
    }

    /**
     * Create a group.
     *
     * @param array $attributes Default attributes of the group
     *
     * @return bool|string
     */
    public function create(array $attributes)
    {
        $group = new Group($attributes);

        $group->validateRequired();

        // Reset the container by reversing the current container
        $group->setAttribute('container', array_reverse($group->getAttribute('container')));

        $add['cn'] = $group->getAttribute('group_name');
        $add['samaccountname'] = $group->getAttribute('group_name');
        $add['objectClass'] = 'Group';
        $add['description'] = $group->getAttribute('description');

        $container = 'OU='.implode(',OU=', $group->getAttribute('container'));

        $dn = 'CN='.$add['cn'].', '.$container.','.$this->adldap->getBaseDn();

        return $this->connection->add($dn, $add);
    }

    /**
     * Delete a group account.
     *
     * @param string $group The group to delete (please be careful here!)
     *
     * @return bool|string
     */
    public function delete($group)
    {
        $this->adldap->utilities()->validateNotNull('Group', $group);

        $this->adldap->utilities()->validateLdapIsBound();

        $groupInfo = $this->info($group, ['*']);

        $dn = $groupInfo[0]['distinguishedname'][0];

        return $this->adldap->folder()->delete($dn);
    }

    /**
     * Rename a group.
     *
     * @param string $groupName The group to rename
     * @param string $newName The new name to give the group
     * @param array  $container
     *
     * @return bool
     */
    public function rename($groupName, $newName, $container)
    {
        $groupInfo = $this->find($groupName);

        if(is_array($groupInfo) && array_key_exists('dn', $groupInfo)) {
            $newRDN = 'CN='.$newName;

            // Determine the container
            $container = array_reverse($container);
            $container = 'OU='.implode(', OU=', $container);

            $dn = $container.', '.$this->adldap->getBaseDn();

            return $this->connection->rename($groupInfo['dn'], $newRDN, $dn, true);
        }

        return false;
    }

    /**
     * Remove a group from a group.
     *
     * @param string $parentName The parent group name
     * @param string $childName The child group name
     *
     * @return bool
     */
    public function removeGroup($parentName, $childName)
    {
        $parentDn = $this->dn($parentName);

        $childDn = $this->dn($childName);

        if($parentDn && $childDn) {
            $del = [];
            $del['member'] = $childDn;

            return $this->connection->modDelete($parentDn, $del);
        }

        return false;
    }

    /**
     * Remove a user from a group.
     *
     * @param string $groupName The group to remove a user from
     * @param string $username The AD user to remove from the group
     *
     * @return bool
     */
    public function removeUser($groupName, $username)
    {
        $groupDn = $this->dn($groupName);

        $userDn = $this->adldap->user()->dn($username);

        if($groupDn && $userDn) {
            $del = [];
            $del['member'] = $userDn;

            return $this->connection->modDelete($groupDn, $del);
        }

        return false;
    }

    /**
     * Remove a contact from a group.
     *
     * @param string $group     The group to remove a user from
     * @param string $contactDn The DN of a contact to remove from the group
     *
     * @return bool
     */
    public function removeContact($group, $contactDn)
    {
        // Find the parent dn
        $groupDn = $this->dn($group);

        if($groupDn && $contactDn)
        {
            $del = [];
            $del['member'] = $contactDn;

            return $this->connection->modDelete($groupDn, $del);
        }

        return false;
    }

    /**
     * Return a list of groups in a group.
     *
     * @param string $group     The group to query
     * @param null   $recursive Recursively get groups
     *
     * @return array|bool
     */
    public function inGroup($group, $recursive = null)
    {
        $this->adldap->utilities()->validateLdapIsBound();

        // Use the default option if they haven't set it
        if ($recursive === null) {
            $recursive = $this->adldap->getRecursiveGroups();
        }

        // Search the directory for the members of a group
        $info = $this->info($group);

        $groups = $info['member'];

        if (! is_array($groups)) {
            return false;
        }

        $groupArray = [];

        for ($i = 0; $i < $groups['count']; $i++) {
            $filter = '(&(objectCategory=group)(distinguishedName='.$this->adldap->utilities()->ldapSlashes($groups[$i]).'))';

            $fields = ['samaccountname', 'distinguishedname', 'objectClass'];

            $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

            $entries = $this->connection->getEntries($results);

            // not a person, look for a group
            if ($entries['count'] == 0 && $recursive === true) {
                $filter = '(&(objectCategory=group)(distinguishedName='.$this->adldap->utilities()->ldapSlashes($groups[$i]).'))';

                $fields = ['distinguishedname'];

                $results = $this->connection->search($this->adldap->getBaseDn(), $filter, $fields);

                $entries = $this->connection->getEntries($results);

                if (! isset($entries['distinguishedname'][0])) {
                    continue;
                }

                $subGroups = $this->inGroup($entries['distinguishedname'], $recursive);

                if (is_array($subGroups)) {
                    $groupArray = array_merge($groupArray, $subGroups);
                    $groupArray = array_unique($groupArray);
                }

                continue;
            }

            $groupArray[] = $entries[0]['distinguishedname'][0];
        }

        return $groupArray;
    }

    /**
     * Return a list of members in a group.
     *
     * @param string $group  The group to query
     * @param array  $fields The fields to retrieve for each member
     *
     * @return array|bool
     */
    public function members($group, $fields = [])
    {
        $group = $this->info($group);

        if (is_array($group) && array_key_exists('member', $group)) {
            $members = [];

            foreach ($group['member'] as $member) {
                $members[] = $this->adldap->search()
                    ->setDn($member)
                    ->select($fields)
                    ->where('objectClass', '=', 'user')
                    ->where('objectClass', '=', 'person')
                    ->first();
            }

            return $members;
        }

        return false;
    }

    /**
     * Group Information. Returns a collection.
     *
     * The group name is case sensitive.
     *
     * @param string $groupName The group name to retrieve info about
     * @param null   $fields    Fields to retrieve
     * @param bool   $isGUID    Is the groupName passed a GUID or a name
     *
     * @return \Adldap\collections\AdldapGroupCollection|bool
     * @depreciated
     */
    public function infoCollection($groupName, $fields = null, $isGUID = false)
    {
        $info = $this->info($groupName, $fields, $isGUID);

        if ($info) {
            return new AdldapGroupCollection($info, $this->adldap);
        }

        return false;
    }

    /**
     * Return a complete list of "groups in groups".
     *
     * @param string $groupName The group to get the list from
     *
     * @return array|bool
     */
    public function recursiveGroups($groupName)
    {
        $groups = [];

        $info = $this->find($groupName);

        if (is_array($info) && array_key_exists('cn', $info)) {
            $groups[] = $info['dn'];

            if (array_key_exists('memberof', $info)) {
                if (is_array($info['memberof'])) {
                    foreach ($info['memberof'] as $group) {
                        $explodedDn = $this->connection->explodeDn($group);

                        $groups = array_merge($groups, $this->recursiveGroups($explodedDn[0]));
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * Returns a complete list of security groups in AD.
     *
     * @param bool   $includeDescription Whether to return a description
     * @param string $search             Search parameters
     * @param bool   $sorted             Whether to sort the results
     *
     * @return array|bool
     */
    public function allSecurity($includeDescription = false, $search = '*', $sorted = true)
    {
        return $this->search(Adldap::ADLDAP_SECURITY_GLOBAL_GROUP, $includeDescription, $search, $sorted);
    }

    /**
     * Returns a complete list of distribution lists in AD.
     *
     * @param bool   $includeDescription Whether to return a description
     * @param string $search             Search parameters
     * @param bool   $sorted             Whether to sort the results
     *
     * @return array|bool
     */
    public function allDistribution($includeDescription = false, $search = '*', $sorted = true)
    {
        return $this->search(Adldap::ADLDAP_DISTRIBUTION_GROUP, $includeDescription, $search, $sorted);
    }

    /**
     * Coping with AD not returning the primary group
     * http://support.microsoft.com/?kbid=321360.
     *
     * This is a re-write based on code submitted by Bruce which prevents the
     * need to search each security group to find the true primary group
     *
     * @param string $groupId Group ID
     * @param string $userId  User's Object SID
     *
     * @return bool
     */
    public function getPrimaryGroup($groupId, $userId)
    {
        $this->adldap->utilities()->validateNotNull('Group ID', $groupId);
        $this->adldap->utilities()->validateNotNull('User ID', $userId);

        $groupId = substr_replace($userId, pack('V', $groupId), strlen($userId) - 4, 4);

        $sid = $this->adldap->utilities()->getTextSID($groupId);

        $result = $this->adldap->search()
                ->where('objectsid', '=', $sid)
                ->first();

        if (is_array($result) && array_key_exists('dn', $result)) {
            return $result['dn'];
        }

        return false;
    }
}