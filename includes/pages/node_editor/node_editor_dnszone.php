<?php
/*
 * WiND - Wireless Nodes Database
 *
 * Copyright (C) 2005-2014 	by WiND Contributors (see AUTHORS.txt)
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

class node_editor_dnszone {

	var $tpl;
	
	function __construct() {
		
	}
	
	function form_zone() {
		global $construct, $db, $vars;
		$form_zone = new form(array('FORM_NAME' => 'form_zone'));
		$form_zone->db_data((get('zone')=='add'?'dns_zones.name, dns_zones.info, ':'').'dns_zones_nameservers.nameserver_id');
		if (get('zone')=='add') {
			if (get('type') == 'reverse') {
				$ipr = $db->get("ip_start, ip_end",
						"ip_ranges",
						"node_id = ".intval(get('node')));
				foreach( (array) $ipr as $key => $value) {
					$ipr[$key]['ip_start'] = long2ip($value['ip_start']);
					$ipr[$key]['ip_end'] = long2ip($value['ip_end']);
					$ipr[$key]['value'] = reverse_zone_from_ip($ipr[$key]['ip_start']);
					$ipr[$key]['output'] = $ipr[$key]['value']." [".$ipr[$key]['ip_start'].' - '.$ipr[$key]['ip_end']."]";
				}
				$form_zone->db_data_enum('dns_zones.name', $ipr);
			} elseif (get('type') == 'reverse_v6') {
				$ipr = $db->get("v6net, v6prefix",
						"ip_ranges_v6",
						"node_id = ".intval(get('node')));
				foreach( (array) $ipr as $key => $value) {
					$ipr[$key]['v6net'] = varbinary2ipv6number($value['v6net']);
					$ipr[$key]['value'] = reverse_zone_from_ipv6($ipr[$key]['v6net'],(int)$ipr[$key]['v6prefix']);
					$ipr[$key]['output'] = $ipr[$key]['value']." [".$ipr[$key]['v6net'].'/'.$ipr[$key]['v6prefix']."]";
				}
				$form_zone->db_data_enum('dns_zones.name', $ipr);
			} else {
				$form_zone->data[0]['value'] = $db->get('name_ns', 'nodes', "id = ".intval(get('node')));
				$form_zone->data[0]['value'] = $form_zone->data[0]['value'][0]['name_ns'];
				$form_zone->data[0]['value'] .= ".".$vars['dns']['root_zone'];
			}
		}

		$form_zone->db_data_pickup(
					"dns_zones_nameservers.nameserver_id",
					"dns_nameservers",
					$db->get('dns_nameservers.id AS value, ' .
							'CONCAT(dns_nameservers.name, ".", nodes.name_ns, ".", "'.$vars['dns']['ns_zone'].'") AS output', 
							"dns_zones_nameservers, dns_nameservers, nodes", 
							"dns_nameservers.node_id = nodes.id AND dns_nameservers.id = dns_zones_nameservers.nameserver_id AND dns_zones_nameservers.zone_id = '".get('zone')."'",
							"",
							"dns_zones_nameservers.id ASC")
					, TRUE);
		return $form_zone;
	}

	function output() {
		if ($_SERVER['REQUEST_METHOD'] == 'POST' && method_exists($this, 'output_onpost_'.$_POST['form_name'])) return call_user_func(array($this, 'output_onpost_'.$_POST['form_name']));
		global $construct;
		$this->tpl['dnszone_method'] = (get('zone') == 'add' ? 'request_'.get('type') : 'edit' );
		$this->tpl['form_zone'] = $construct->form($this->form_zone(), __FILE__);
		return template($this->tpl, __FILE__);
	}

	function output_onpost_form_zone() {
		global $construct, $main, $db, $vars;
		if (substr($_POST['dns_zones__name'], -strlen($vars['dns']['root_zone'])-1) == ".".$vars['dns']['root_zone']) {
			$_POST['dns_zones__name'] = substr($_POST['dns_zones__name'], 0, -strlen($vars['dns']['root_zone'])-1);
		}
		$_POST['dns_zones__name'] = validate_zone($_POST['dns_zones__name']);
		$form_zone = $this->form_zone();
		$ret = TRUE;
		$f = array();
		if (get('zone') == 'add') {
			if ($_POST['dns_zones__name'] == '') {
				if (is_null($_POST['dns_zones__name'])) $main->message->set_fromlang('error', 'zone_invalid_name');
				else $db->output_error_fields_required(array('dns_zones__name'));
				return;
			}
			switch (get('type')) {
				case 'forward':
					if ($_POST['dns_zones__name'].'.'.$vars['dns']['root_zone'] == $vars['dns']['ns_zone']) {
						$main->message->set_fromlang('error', 'zone_reserved_name');
						return;
					}
					break;
				case 'reverse':
					$iprange = $db->get("ip_start, ip_end",
							"ip_ranges",
							"node_id = ".intval(get('node')));
					foreach( (array) $iprange as $value)
						if (reverse_zone_from_ip(long2ip($value['ip_start'])) == $_POST['dns_zones__name']) {
							$valid = TRUE;
							break;
						}
					if (!$valid) {
						$main->message->set_fromlang('error', 'zone_out_of_range');
						return;
					}
					break;
                                case 'reverse_v6':
                                        break;
				default:
					$main->message->set_fromlang('error', 'generic');		
					return;
			}
			$f = array('dns_zones.status' => 'waiting', 'dns_zones.type' => get('type'), "dns_zones.node_id" => intval(get('node')));
			$ret = $form_zone->db_set($f,
									"dns_zones", "id", get('zone'));
		}
		$ins_id = (get('zone')=='add' ? $db->insert_id : get('zone'));
		$ret = $ret && $form_zone->db_set_multi(array(), "dns_zones_nameservers", "zone_id", $ins_id);

		if ($ret) {
			$main->message->set_fromlang('info', (get('zone') == 'add'?'request_dnszone_success':'edit_success'), make_ref('/node_editor', array("node" => intval(get('node')))));
		} else {
			$main->message->set_fromlang('error', 'generic');		
		}
	}

}

?>