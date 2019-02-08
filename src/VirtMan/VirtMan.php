<?php
/**
 * This file is part of the PHP VirtMan package
 *
 * PHP Version 7.2
 * 
 * @category VirtMan
 * @package  VirtMan
 * @author   Ryan Owens <RyanOwens@linux.com>
 * @author   Micky Socaci <micky@nowlive.ro>
 * @license  https://github.com/mickys/VirtMan/blob/master/LICENSE.md MIT
 * @link     https://github.com/mickys/VirtMan/
 */
namespace VirtMan;

// Commands
use VirtMan\Command\CreateMachine;
use VirtMan\Command\CreateNetwork;
use VirtMan\Command\CreateStorage;

// Exceptions
use VirtMan\Exceptions\ImpossibleMemoryAllocationException;
use VirtMan\Exceptions\ImpossibleStorageAllocationException;
use VirtMan\Exceptions\InvalidArchitectureException;

// Models
use VirtMan\Group\Group;
use VirtMan\Machine\Machine;
use VirtMan\Network\Network;
use VirtMan\Storage\Storage;

/**
 * VirtMan main class
 *
 * @category VirtMan
 * @package  VirtMan
 * @author   Ryan Owens <RyanOwens@linux.com>
 * @author   Micky Socaci <micky@nowlive.ro>
 * @license  https://github.com/mickys/VirtMan/blob/master/LICENSE.md MIT
 * @link     https://github.com/mickys/VirtMan/
 */
class VirtMan
{

    /**
     * Library Version
     *
     * @var string
     */
    const VERSION = '0.0.2';

    /**
     * Libvirt Domain Connection
     *
     * @var Libvirt Connection
     */
    private $_connection = null;

    /**
     * Libvirt server User
     *
     * @var string
     */
    private $_authname = null;

    /**
     * Libvirt serrver Password
     *
     * @var string
     */
    private $_passphrase = null;

    /**
     * Maximum amount of memory for all machines
     *
     * @var int
     */
    private $_maxMemory = 0;

    /**
     * Maximum Storage Quota size
     *
     * @var int
     */
    private $_maxQuota = 0;

    /**
     * Array of all available Machine types
     *
     * @var string array
     */
    private $_machineTypes = [];

    /**
     * Arary of all supported Image types
     *
     * @var string array
     */
    private $_imageTypes = [
        'raw',
        'qcow',
        'qcow2',
    ];

    /**
     * VirtMan
     *
     * VirtMan Constructor
     *
     * @return TODO
     */
    public function __construct()
    {
        // Initialize Config Values
        $this->_authname = config('virtman.username');
        $this->_passphrase = config('virtman.password');
        $this->_maxQuota = (int) config('virtman.storageQuota');
        $this->_maxMemory = (int) config('virtman.memoryQuota');
        // Attempt to connect to LibVirt
        $this->_connection = $this->_connect();
        // Initialize Environment Values
        $this->_machineTypes = $this->getMachineTypes();

    }

    /**
     * Libvirt is Installed
     *
     * Checks if the Libvirt PHP bindings are installed
     *
     * @return boolean
     */
    public function libvirtIsInstalled()
    {
        return function_exists('libvirt_version');
    }

    /**
     * Connect
     *
     * Authenticate with Libvirt and get the connection resource
     *
     * @return Libvirt Connection resource
     */
    private function _connect()
    {
        $URI = $this->getConnectionURI();
        return libvirt_connect(
            $URI, false, [
                VIR_CRED_AUTHNAME => $this->_authname,
                VIR_CRED_PASSPHRASE => $this->_passphrase,
            ]
        );
    }

    /**
     * Remaining Memory
     *
     * Amount of memory available for new machines
     *
     * @return int
     */
    public function remainingMemory()
    {
        $memUsed = 0;
        foreach (Machine::all() as $machine) {
            $memUsed += $machine->size;
        }
        return $this->_maxMemory - $memUsed;
    }

    /**
     * Remaining Storage Space
     *
     * Amount of storage space available for new Storage.
     *
     * @return int
     */
    public function remainingStorageSpace()
    {
        $storageUsed = 0;
        foreach (Storage::all() as $storage) {
            $storageUsed += $storage->size;
        }
        return $this->_maxQuota - $storageUsed;
    }

    /**
     * Get Machine Types
     *
     * Returns all of the available machine types.
     *
     * @return array
     */
    public function getMachineTypes()
    {
        $keys = array_keys(libvirt_connect_get_machine_types($this->_connection));
        $types = [];
        foreach ($keys as $type) {
            // Remove trailing NULL character from each machine type
            array_push($types, substr_replace($type, "", -1, 1));
        }
        return $types;
    }

    /**
     * Get Connection URI
     *
     * Read from the VirtMan config to build the connection URI
     *
     * @return string
     */
    public function getConnectionURI()
    {
        $URI = config('virtman.connectionType');
        if (config('virtman.usingSSH')) {
            $URI .= "+ssh:///" . config('virtman.sshUser') . "@";
            $URI .= config('virtman.remoteUrl') . "/" . config('virtman.daemonMode');
        } else {
            $URI .= ":///" . config('virtman.daemonMode');
        }
        return $URI;
    }

    /**
     * Create Network
     *
     * Create a Network Object
     *
     * @param string $mac     Hardware Mac Address
     * @param string $network Network string
     * @param string $model   Network Card model
     * 
     * @return Network
     */
    public function createNetwork(string $mac, string $network, string $model)
    {
        $command = new CreateNetwork($mac, $network, $model, $this->_connection);
        return $command->run();
    }

    /**
     * Create Storage
     *
     * Create a storage object
     *
     * @param string $name Name
     * @param string $type Type
     * @param int    $size Size
     * 
     * @return Storage
     */
    public function createStorage(string $name, string $type, int $size)
    {
        if ($size < 0
            || $size > $this->_maxQuota
            || $size > $this->_remainingStorageSpace()
        ) {
            throw new ImpossibleStorageAllocationException(
                "Attempting to create storage with an impossible size", 1
            );
        }

        $command = new CreateStorage($name, $size, $type, $this->_connection);
        return $command->run();
    }

    /**
     * Create Machine
     *
     * Create a Virtual Machine
     *
     * @param string        $name    Machine name
     * @param string        $type    Machine type
     * @param int           $memory  Machine memory
     * @param int           $numCpus Machine cpus
     * @param string        $arch    Machine architecture
     * @param Storage array $storage Machine storage array
     * @param Network       $network Machine network object
     *
     * @return Machine
     */
    public function createMachine(
        string $name,
        string $type,
        int $memory,
        int $numCpus,
        string $arch,
        array $storage,
        Network $network
    ) {

        if ($memory < 0 
            || $memory > $this->_maxMemory 
            || $memory > $this->_remainingMemory()
        ) {
            throw new ImpossibleMemoryAllocationException(
                "Attempting to create a machine with an impossible memory size.", 1
            );
        }

        if (!in_array($arch, $this->_machineTypes)) {
            throw new InvalidArchitectureException(
                "Attempting to create a machine with an unsupported Architecture",
                1, null, $arch
            );
        }

        $command = new CreateMachine(
            $storage,
            $name,
            $type,
            $arch,
            $memory,
            $numCpus,
            $network,
            $this->_connection
        );
        return $command->run();
    }
}
