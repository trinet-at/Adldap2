<?php

namespace Adldap\Classes;

use Adldap\Exceptions\EntryNotFoundException;
use Adldap\Models\ExchangeServer;
use Adldap\Models\Computer;
use Adldap\Models\Container;
use Adldap\Models\Group;
use Adldap\Models\User;
use Adldap\Models\Printer;
use Adldap\Models\Entry;
use Adldap\Schemas\ActiveDirectory;
use Adldap\Objects\Paginator;
use Adldap\Query\Operator;
use Adldap\Query\Builder;
use Adldap\Adldap;

class Search extends AbstractBase
{
    /**
     * Stores the current query builder instance.
     *
     * @var Builder
     */
    protected $query;

    /**
     * Stores the distinguished name to search on.
     *
     * @var string
     */
    protected $dn = '';

    /**
     * Stores the bool to determine whether or not
     * to search LDAP recursively.
     *
     * @var bool
     */
    protected $recursive = true;

    /**
     * Stores the bool to determine whether or not
     * to search LDAP on the base scope.
     *
     * @var bool
     */
    protected $read = false;

    /**
     * Stores the bool to determine whether or not
     * to return LDAP results in their raw format.
     *
     * @var bool
     */
    protected $raw = false;

    /**
     * Stores the field to sort search results by.
     *
     * @var string
     */
    protected $sortByField = '';

    /**
     * Stores the direction to sort the search results by.
     *
     * @var string
     */
    protected $sortByDirection = 'DESC';

    /**
     * Constructor.
     *
     * @param Adldap $adldap
     */
    public function __construct(Adldap $adldap)
    {
        parent::__construct($adldap);

        $this->setQueryBuilder(new Builder($this->connection));
    }

    /**
     * Performs the specified query on the current LDAP connection.
     *
     * @param string $query
     *
     * @return array|bool
     */
    public function query($query)
    {
        $dn = $this->getDn();

        $selects = $this->getQueryBuilder()->getSelects();

        if ($this->read) {
            // If read is true, we'll perform a read search, retrieving one record
            $results = $this->connection->read($dn, $query, $selects);
        } elseif ($this->recursive) {
            // If recursive is true, we'll perform a recursive search
            $results = $this->connection->search($dn, $query, $selects);
        } else {
            // Read and recursive is false, we'll return a listing
            $results = $this->connection->listing($dn, $query, $selects);
        }

        if ($results) {
            $objects = $this->processResults($results);

            if (!empty($this->sortByField)) {
                $objects =  $this->processSortBy($objects);
            }

            return $objects;
        }

        return false;
    }

    /**
     * Performs the current query on the current LDAP connection.
     *
     * @return array|bool
     */
    public function get()
    {
        return $this->query($this->getQuery());
    }

    /**
     * Performs a global 'all' search query on the
     * current connection.
     *
     * @return array|bool
     */
    public function all()
    {
        $this->query->where(ActiveDirectory::COMMON_NAME, Operator::$wildcard);

        return $this->get();
    }

    /**
     * Returns the first entry in a search result.
     *
     * @return array|bool
     */
    public function first()
    {
        $results = $this->get();

        if (is_array($results) && array_key_exists(0, $results)) {
            return $results[0];
        }

        // No entries were returned, return false
        return false;
    }

    /**
     * Adds the inserted fields to query on the current LDAP connection.
     *
     * @param array $fields
     *
     * @return $this
     */
    public function select($fields = [])
    {
        $this->query->select($fields);

        return $this;
    }

    /**
     * Adds a where clause to the current query.
     *
     * @param string $field
     * @param string $operator
     * @param string $value
     *
     * @return $this
     */
    public function where($field, $operator = null, $value = null)
    {
        $this->query->where($field, $operator, $value);

        return $this;
    }

    /**
     * Adds a where contains clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return $this
     */
    public function whereContains($field, $value)
    {
        $this->query->whereContains($field, $value);

        return $this;
    }

    /**
     * Adds a where starts with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return $this
     */
    public function whereStartsWith($field, $value)
    {
        $this->query->whereStartsWith($field, $value);

        return $this;
    }

    /**
     * Adds a where ends with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return $this
     */
    public function whereEndsWith($field, $value)
    {
        $this->query->whereEndsWith($field, $value);

        return $this;
    }

    /**
     * Adds an or where clause to the current query.
     *
     * @param string $field
     * @param string $operator
     * @param string $value
     *
     * @return $this
     */
    public function orWhere($field, $operator = null, $value = null)
    {
        $this->query->orWhere($field, $operator, $value);

        return $this;
    }

    /**
     * Adds an or where contains clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return $this
     */
    public function orWhereContains($field, $value)
    {
        $this->query->orWhereContains($field, $value);

        return $this;
    }

    /**
     * Adds an or where starts with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return $this
     */
    public function orWhereStartsWith($field, $value)
    {
        $this->query->orWhereStartsWith($field, $value);

        return $this;
    }

    /**
     * Adds an or where ends with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return $this
     */
    public function orWhereEndsWith($field, $value)
    {
        $this->query->orWhereEndsWith($field, $value);

        return $this;
    }

    /**
     * Returns the current LDAP query string.
     *
     * @return string
     */
    public function getQuery()
    {
        return $this->query->get();
    }

    /**
     * Returns the current query Builder instance.
     *
     * @return Builder
     */
    public function getQueryBuilder()
    {
        return $this->query;
    }

    /**
     * Paginates the current LDAP query.
     *
     * @param int  $perPage
     * @param int  $currentPage
     * @param bool $isCritical
     *
     * @return Paginator|bool
     */
    public function paginate($perPage = 50, $currentPage = 0, $isCritical = true)
    {
        // Stores all LDAP entries in a page array
        $pages = [];

        $cookie = '';

        do {
            $this->connection->controlPagedResult($perPage, $isCritical, $cookie);

            $results = $this->connection->search($this->getDn(), $this->getQuery(), $this->getQueryBuilder()->getSelects());

            if ($results) {
                $this->connection->controlPagedResultResponse($results, $cookie);

                $pages[] = $results;
            }
        } while ($cookie !== null && !empty($cookie));

        if (count($pages) > 0) {
            return $this->processPaginatedResults($pages, $perPage, $currentPage);
        }

        return false;
    }

    /**
     * Sorts the LDAP search results by the specified field
     * and direction.
     *
     * @param string $field
     * @param string $direction
     *
     * @return $this
     */
    public function sortBy($field, $direction = 'desc')
    {
        $this->sortByField = $field;

        if (strtolower($direction) === 'asc') {
            $this->sortByDirection = SORT_ASC;
        } else {
            $this->sortByDirection = SORT_DESC;
        }

        return $this;
    }

    /**
     * Sets the complete distinguished name to search on.
     *
     * @param string $dn
     *
     * @return $this
     */
    public function setDn($dn)
    {
        if ($dn === null) {
            $this->dn = null;
        } else {
            $this->dn = (string) $dn;
        }

        return $this;
    }

    /**
     * Returns the current distinguished name.
     *
     * This will return the domains base DN if a search
     * DN is not set.
     *
     * @return string
     */
    public function getDn()
    {
        if ($this->dn === null) {
            return $this->dn;
        } else if (empty($this->dn)) {
            return $this->getBaseDn();
        }

        return $this->dn;
    }

    /**
     * Retrieves the current base DN.
     *
     * @return string
     */
    public function getBaseDn()
    {
        $baseDn = $this->getAdldap()->getConfiguration()->getBaseDn();

        if (empty($baseDn)) {
            $this->findBaseDn();
        }

        return $baseDn;
    }

    /**
     * Finds a record using ambiguous name resolution.
     *
     * @param string $anr
     *
     * @return array|bool
     */
    public function find($anr)
    {
        return $this->where(ActiveDirectory::ANR, '=', $anr)->first();
    }

    /**
     * Finds a record using ambiguous name resolution. If a record
     * is not found, an exception is thrown.
     *
     * @param string $anr
     *
     * @return array|bool
     *
     * @throws EntryNotFoundException
     */
    public function findOrFail($anr)
    {
        $entry = $this->find($anr);

        // Make sure we check if the result is an entry or an array before
        // we throw an exception in case the user wants raw results.
        if(!$entry instanceof Entry && !is_array($entry)) {
            $message = 'Unable to find record in Active Directory.';

            throw new EntryNotFoundException($message);
        }

        return $entry;
    }

    /**
     * Finds a record by its distinguished name.
     *
     * @param string $dn
     *
     * @return array|bool
     */
    public function findByDn($dn)
    {
        return $this->setDn($dn)
            ->read(true)
            ->where(ActiveDirectory::OBJECT_CLASS, '*')
            ->first();
    }

    /**
     * Finds the Base DN of your domain controller.
     *
     * @return string|bool
     */
    public function findBaseDn()
    {
        $result = (new self($this->getAdldap()))
            ->setDn(null)
            ->read()
            ->raw()
            ->where(ActiveDirectory::OBJECT_CLASS, '*')
            ->first();

        $key = ActiveDirectory::DEFAULT_NAMING_CONTEXT;

        if (is_array($result) && array_key_exists($key, $result)) {
            if(array_key_exists(0, $result[$key])) {
                return $result[$key][0];
            }
        }

        return false;
    }

    /**
     * Sets the recursive property to tell the search
     * whether or not to search recursively.
     *
     * @param bool $recursive
     *
     * @return $this
     */
    public function recursive($recursive = true)
    {
        $this->recursive = (bool) $recursive;

        return $this;
    }

    /**
     * Sets the recursive property to tell the search
     * whether or not to search on the base scope and
     * return a single entry.
     *
     * @param bool $read
     *
     * @return $this
     */
    public function read($read = true)
    {
        $this->read = (bool) $read;

        return $this;
    }

    /**
     * Sets the recursive property to tell the search
     * whether or not to return the LDAP results in
     * their raw format.
     *
     * @param bool $raw
     *
     * @return $this
     */
    public function raw($raw = true)
    {
        $this->raw = (bool) $raw;

        return $this;
    }

    /**
     * Returns a new LDAP Entry instance.
     *
     * @param array $attributes
     *
     * @return Entry
     */
    public function newLdapEntry(array $attributes)
    {
        $attribute = ActiveDirectory::OBJECT_CATEGORY;

        if(array_key_exists($attribute, $attributes) && array_key_exists(0, $attributes[$attribute])) {
            // We'll explode the DN so we can grab it's object category.
            $category = Utilities::explodeDn($attributes[$attribute][0]);

            // We'll create a new object depending on the object category of the LDAP entry.
            switch(strtolower($category[0])) {
                case ActiveDirectory::OBJECT_CATEGORY_COMPUTER:
                    return (new Computer([], $this->connection))->setRawAttributes($attributes);
                case ActiveDirectory::OBJECT_CATEGORY_PERSON:
                    return (new User([], $this->connection))->setRawAttributes($attributes);
                case ActiveDirectory::OBJECT_CATEGORY_GROUP:
                    return (new Group([], $this->connection))->setRawAttributes($attributes);
                case ActiveDirectory::MS_EXCHANGE_SERVER:
                    return (new ExchangeServer([], $this->connection))->setRawAttributes($attributes);
                case ActiveDirectory::OBJECT_CATEGORY_CONTAINER:
                    return (new Container([], $this->connection))->setRawAttributes($attributes);
                case ActiveDirectory::OBJECT_CATEGORY_PRINTER:
                    return (new Printer($attributes, $this->connection))->setRawAttributes();
            }
        }

        // A default entry object if the object category isn't recognized.
        return (new Entry($attributes, $this->connection))->setRawAttributes($attributes);
    }

    /**
     * Sets the query property.
     *
     * @param Builder $query
     */
    private function setQueryBuilder(Builder $query)
    {
        $this->query = $query;
    }

    /**
     * Processes LDAP search results into a nice array.
     *
     * @param resource $results
     *
     * @return array
     */
    private function processResults($results)
    {
        $entries = $this->connection->getEntries($results);

        if($this->raw) {
            return $entries;
        } else {
            $objects = [];

            if (array_key_exists('count', $entries)) {
                for ($i = 0; $i < $entries['count']; $i++) {
                    $objects[] = $this->newLdapEntry($entries[$i]);
                }
            }

            return $objects;
        }
    }

    /**
     * Processes paginated LDAP results.
     *
     * @param array $pages
     * @param int   $perPage
     * @param int   $currentPage
     *
     * @return Paginator|bool
     */
    private function processPaginatedResults($pages, $perPage = 50, $currentPage = 0)
    {
        // Make sure we have at least one page of results
        if (count($pages) > 0) {
            $objects = [];

            // Go through each page and process the results into an objects array
            foreach ($pages as $results) {
                $objects = array_merge($objects, $this->processResults($results));
            }

            // Return a new Paginator instance
            return new Paginator($objects, $perPage, $currentPage, count($pages));
        }

        // Looks like we don't have any results, return false
        return false;
    }

    /**
     * Processes the array of specified object results
     * and sorts them by the field and direction search
     * property.
     *
     * @param array $objects
     *
     * @return array
     */
    private function processSortBy($objects)
    {
        if (count($objects) > 0) {
            foreach ($objects as $key => $row) {
                if (array_key_exists($this->sortByField, $row)) {
                    $sort[$key] = $row[$this->sortByField];
                }
            }

            if(isset($sort) && is_array($sort)) {
                array_multisort($sort, $this->sortByDirection, $objects);
            }
        }

        return $objects;
    }
}
