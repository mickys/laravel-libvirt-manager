<?php
/**
 * This file is part of the PHP VirtMan package
 *
 * PHP Version 7.2
 * 
 * @category VirtMan
 * @package  VirtMan
 * @author   Micky Socaci <micky@nowlive.ro>
 * @license  https://github.com/mickys/VirtMan/blob/master/LICENSE.md MIT
 * @link     https://github.com/mickys/VirtMan/
 */
namespace VirtMan\Lib;

/**
 * VirtMan lib network class
 *
 * @category VirtMan\Lib
 * @package  VirtMan
 * @author   Micky Socaci <micky@nowlive.ro>
 * @license  https://github.com/mickys/VirtMan/blob/master/LICENSE.md MIT
 * @link     https://github.com/mickys/VirtMan/
 */
class Network
{

    public static function getDNAT($nodeIP, $GWInterface, $dhcpItems) {

        $httpRecords = ["# node - ".$nodeIP." HTTP "];
        $tusdRecords = ["# node - ".$nodeIP." TUSD "];
        foreach($dhcpItems as $item) {
            $httpRecords[]= "/sbin/iptables -t nat -A PREROUTING -i ".$GWInterface." -p tcp --dport ".$item->http_port." -j DNAT --to-destination ".$item->ip.":80";
            $tusdRecords[]= "/sbin/iptables -t nat -A PREROUTING -i ".$GWInterface." -p tcp --dport ".$item->tusd_port." -j DNAT --to-destination ".$item->ip.":1080";
        }

        return array_merge($httpRecords, $tusdRecords);
    }
    /**
     * Get network DHCP XML
     *
     * @return string
     */
    public static function getNetworkDHCPXML($node, $dhcpItems)
    {
        $XML = '<network>'."\n";
        $XML.= '    <name>default</name>'."\n";
        
        // leave uuid blank so it gets generated by libvirt
        $XML.= '    <uuid></uuid>'."\n";

        $XML.= '    <forward mode="route" dev="'.$node->interface_0_name.'">'."\n";
        $XML.= '        <interface dev="'.$node->interface_0_name.'" />'."\n";
        $XML.= '    </forward>'."\n";

        $XML.= '    <bridge name="'.$node->interface_1_name.'" stp="on" delay="0"/>'."\n";
        $XML.= '    <mac address="'.$node->interface_1_mac.'"/>'."\n";
        $XML.= '    <ip address="'.$node->interface_1_ip.'" netmask="'.$node->interface_1_netmask.'" localPtr="yes">'."\n";
        $XML.= '        <dhcp>'."\n";
        
        // no ranges
        // $XML.= '            <range start="192.168.122.10" end="192.168.122.100"/>'."\n";

        $i = 0;
        foreach ($dhcpItems as $item) {
            $XML.= '            <host mac="'.$item->mac.'" name="'.$item->name.'" ip="'.$item->ip.'"/>'."\n";
        }
        $XML.= '        </dhcp>'."\n";
        $XML.= '    </ip>'."\n";
        $XML.= '</network>'."\n";

        return $XML;
    }

    /**
     * Generate new unused Mac address
     *
     * @param string $hypervisor_name Hypervisor name
     * @param int    $seed            uintSeed
     * 
     * @return string
     */
    public static function generateRandomMacAddress(
        string $hypervisor_name, $seed=false
    ) {

        if (!$seed) {
            $seed = 1;
        }

        if ($hypervisor_name == 'qemu') {
            $prefix = '52:54:00';
        } else {
            if ($hypervisor_name == 'xen') {
                $prefix = '00:16:3e';
            } else {
                $prefix = self::macbyte(($seed * rand()) % 256).':'.
                    self::macbyte(($seed * rand()) % 256).':'.
                    self::macbyte(($seed * rand()) % 256);
            }
        }
        return $prefix.':'.
            self::macbyte(($seed * rand()) % 256).':'.
            self::macbyte(($seed * rand()) % 256).':'.
            self::macbyte(($seed * rand()) % 256);
    }

    /**
     * Generate new unused Mac address
     *
     * @param int $val Value
     *
     * @return int
     */
    public static function macbyte(int $val)
    {
        if ($val < 16) {
            return '0'.dechex($val);
        }
        return dechex($val);
    }

    /**
     * Generate new unused Mac address
     *
     * @param string $hypervisor_name Hypervisor name
     *
     * @return string
     */
    public static function genMacAddress(string $hypervisor_name = "qemu")
    {
        $mac = self::generateRandomMacAddress($hypervisor_name);
        // check if it's unused
        $virtman_networks = \VirtMan\Model\Network\Network::where(
            ['mac' => $mac]
        )->first();
        
        $virtman_dhcp = \VirtMan\Model\Network\DhcpItem::where(
            ['mac' => $mac]
        )->first();

        if (isset($virtman_networks->id) || isset($virtman_dhcp->id)) {
            $mac = self::genMacAddress($hypervisor_name);
        }
        return $mac;
    }

    /**
     * Generate the next ip Address 
     *
     * @param string $ip 
     * 
     * @return string
     */
    public static function getNextIpAfter(string $ip)
    {
        $long = ip2long($ip);
        $result = long2ip(++$long);
        if (!self::validaUsableIpv4Address($result)) {
            return self::getNextIpAfter($result);
        }
        return $result;
    }

    /**
     * Generate the next ip Address 
     *
     * @param string $ip 
     * 
     * @return string
     */
    public static function validaUsableIpv4Address(string $ip)
    {
        $str = explode(".", $ip);
        if (count($str) === 4) {
            if ($str[3] > 0 && $str[3] < 255) {
                return true;
            }
        } 
        return false;
    }

    /**
     * Get free ip and mac address for our new container
     *
     * @param int $node_id 
     * 
     * @return array
     */
    public static function getFreeIpAndMacResource($node_id)
    {
        return \VirtMan\Model\Network\DhcpItem::where("node", "=", $node_id)->orderBy('id', "ASC")->first();
    }

    public static function getFreeMacAddress($used = []) {
        $newMac = self::genMacAddress();
        if (in_array($newMac, $used)) {
            return self::getFreeMacAddress($used);
        }
        return $newMac;
    }
}