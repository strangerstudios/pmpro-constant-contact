<?php
/*
Plugin Name: Paid Memberships Pro - Constant Contact Add On
Plugin URI: http://www.paidmembershipspro.com/pmpro-constantcontact/
Description: Sync your WordPress users and members with Constant Contact lists.
Version: 1.0.3
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/
/*
	Copyright 2011	Stranger Studios	(email : jason@strangerstudios.com)
	GPLv2 Full license details in license.txt
*/

use Ctct\ConstantContact;
use Ctct\Components\Contacts\Contact;
use Ctct\Components\Contacts\CustomField;
use Ctct\Components\Contacts\ContactList;
use Ctct\Components\Contacts\EmailAddress;
use Ctct\Exceptions\CtctException;

// Unsubscribe constants.
define('UNSUB_NO', 0);
define('UNSUB_YES', 1);
define('UNSUB_MANAGED', 2);

//init
function pmprocc_init()
{
	//error_log("PMPROCC_INIT");
	// Include ConstantContact Class if we don't have it already.
	if (!class_exists('Ctct\\ConstantContact') && !class_exists('Ctct\\SplClassLoader')) {
		require_once dirname(__FILE__) . '/includes/Ctct/autoload.php';
	}

	//get options for below
	$options = get_option("pmprocc_options");
	
	//setup hooks for new users	
	if(!empty($options['users_lists']))
	{
		add_action("user_register", "pmprocc_user_register");
	}
	
	//setup hooks for PMPro levels
	pmprocc_getPMProLevels();
	global $pmprocc_levels;
	if(!empty($pmprocc_levels))
	{		
		add_action("pmpro_after_change_membership_level", "pmprocc_pmpro_after_change_membership_level", 15, 2);
	}	
}
add_action("init", "pmprocc_init", 0);

//use a different action if we are on the checkout page
function pmprocc_wp()
{
	if(is_admin())
		return;
		
	global $post;
	if(!empty($post->post_content) && strpos($post->post_content, "[pmpro_checkout]") !== false)
	{
		remove_action("pmpro_after_change_membership_level", "pmprocc_pmpro_after_change_membership_level");
		add_action("pmpro_after_checkout", "pmprocc_pmpro_after_checkout", 15);
		
	}
}
add_action("wp", "pmprocc_wp", 0);

//for when checking out
function pmprocc_pmpro_after_checkout($user_id)
{
	pmprocc_pmpro_after_change_membership_level(intval($_REQUEST['level']), $user_id);
}

//subscribe users when they register
function pmprocc_user_register($user_id)
{
	//error_log("registering user $user_id");
	clean_user_cache($user_id);
	
	$options = get_option("pmprocc_options");
	
	//should we add them to any lists?
	if(!empty($options['users_lists']) && !empty($options['api_key']))
	{
		//get user info
		$list_user = get_userdata($user_id);
		
		//get CC API object
		$api = new ConstantContact($options['api_key']);
		
		//subscribe them
		try
		{
			// check to see if a contact with the email addess already exists in the account
			$response = $api->contactService->getContacts($options['access_token'], array('email' => $list_user->user_email));
			$is_new_contact = empty($response->results);
			// create a new contact if one does not exist
			if ($is_new_contact)
			{
				$contact = new Contact();
				$contact->addEmail($list_user->user_email);
				$contact->first_name = $list_user->first_name;
				$contact->last_name = $list_user->last_name;
			}
			else {
				$contact = $response->results[0];
			}
			// Add each of our default users_lists to 
			foreach($options['users_lists'] as $list)
			{					
				$contact->addList($list->id);
			}
			if ($is_new_contact) {
                                $api->contactService->addContact($options['access_token'], $contact);
			}
			else {
                                $api->contactService->updateContact($options['access_token'], $contact);
			}
		}
		
		//Catch any errors so the user can't see them.
		catch (CtctException $ex)
		{
			// Log the error.
			error_log("error adding user to Constant Contact: $ex");
		}
	}
}

//subscribe new members (PMPro) when they register
function pmprocc_pmpro_after_change_membership_level($level_id, $user_id)
{

	clean_user_cache($user_id);
	
	global $pmprocc_levels;
	$options = get_option("pmprocc_options");
	//error_log("AFTER_CHANGE_MEMBERSHIP: unsubscribe level = " . $options['unsubscribe']);
	
	
	$all_lists = get_option("pmprocc_all_lists");	
	
	// Can't do anything without an api key.
	if (empty($options['api_key'])) {
		error_log("***** AFTER_CHANGE_MEMBERSHIP: CANNOT CONTINUE: no api_key ******");
		return;
	}
	
	
	//should we add them to any lists?
	if(!empty($options['level_' . $level_id . '_lists']))
	{
		//error_log("AFTER_CHANGE_MEMBERSHIP: subscribing to CC ID = " . print_r($options['level_' . $level_id . '_lists'], true));
		
		
		//get user info
		$list_user = get_userdata($user_id);		
		
		//subscribe to each list
		$api = new ConstantContact($options['api_key']);
				
		foreach($options['level_' . $level_id . '_lists'] as $list)
		{		
			//echo "<hr />Trying to subscribe to " . $list . "...";
			
			//subscribe them
			try
			{
				// Check to see if a contact with the email addess already exists in the account
			        $response = $api->contactService->getContacts($options['access_token'], array('email' => $list_user->user_email));
				
				// Create a new contact if one does not exist
				if (empty($response->results))
				{
					$contact = new Contact();
					$contact->addEmail($list_user->user_email);
					$contact->addList($list);
					$contact->first_name = $list_user->first_name;
					$contact->last_name = $list_user->last_name;
                                        $api->contactService->addContact($options['access_token'], $contact);
				}      			
				else
				{
					// Update the existing contact.
					$contact = $response->results[0];
					$contact->addList($list);
					$contact->first_name = $list_user->first_name;
					$contact->last_name = $list_user->last_name;
                                        $returnContact = $api->contactService->updateContact($options['access_token'], $contact);
				}
      			
        
       		}        	
			//Catch any errors so the user can't see them.
			catch (CtctException $ex)
			{
				// Log the error.
				error_log("error setting Constant Contact list to user: $ex");
			}
		}
		
		//unsubscribe them from lists not selected.
		
		if($options['unsubscribe'] !== UNSUB_NO)
		{
			
			switch($options['unsubscribe']) {
				case UNSUB_YES:
					//error_log("getting ALL ids to remove");
					$list_ids_to_remove = pmprocc_pluck('id', $all_lists);
					break;
				case UNSUB_MANAGED:
					//error_log("getting MANAGED ids to remove");
					$list_ids_to_remove = pmprocc_flatten(pmprocc_getSubarrayExcept($options, 'level_' . $level_id . '_lists', '^level_\\d+_lists$'));
					break;
				default:
					throw new Exception("unhandled unsubscribe option " . $options['unsubscribe']);
			}
			//error_log("IDs to Remove = " . print_r($list_ids_to_remove, true));
			
			foreach($list_ids_to_remove as $id)
			{
				if(!in_array($id, $options['level_' . $level_id . '_lists']))
				{
					pmprocc_deleteFromList($options['access_token'], $list_user->user_email, $id);
				}
			}
		}
	}
	else
	{
		//now they are a normal user should we add them to any lists?
		if(!empty($options['users_lists']))
		{
			//get user info
			$list_user = get_userdata($user_id);
			
			//subscribe to each list
			$api = new ConstantContact($options['api_key']);
			foreach($options['users_lists'] as $list)
			{					
				//subscribe them
				try
				{
					// check to see if a contact with the email addess already exists in the account
			                $response = $api->contactService->getContacts($options['access_token'], array('email' => $list_user->user_email));

					// create a new contact if one does not exist
					if (empty($response->results))
					{
						$contact = new Contact();
						$contact->addEmail($list_user->user_email);
						$contact->addList($list);
						$contact->first_name = $list_user->first_name;
						$contact->last_name = $list_user->last_name;
						
						//filter
						$custom_fields = apply_filters("pmpro_constant_contact_custom_fields", array(), $list_user);
						if(!empty($custom_fields))
						{
							foreach($custom_fields as $field)
							{
								$custom_field = new CustomField();
								$custom_field->name = $field['name'];
								$custom_field->value = $field['value'];
														
								$contact->addCustomField($custom_field);
							}
						}		
						
                                                $api->contactService->addContact($options['access_token'], $contact, array('action_by' => 'ACTION_BY_VISITOR')); 
					}	
				}			
				//Catch any errors so the user can't see them.
				catch (CtctException $ex)
				{
					// Log the error.
					error_log("error adding user to the normal constant contact list Constant Contact: $ex");
				}						
			}
			
			//unsubscribe from any list not assigned to users
			if($options['unsubscribe'] !== UNSUB_NO)
			{
				switch($options['unsubscribe']) {
					case UNSUB_YES:
						$list_ids_to_remove = pmprocc_pluck('id', $all_lists);
						break;
					case UNSUB_MANAGED:
						$list_ids_to_remove = pmprocc_flatten(pmprocc_getSubarrayExcept($options, '', '^level_\\d+_lists$'));
						break;
					default:
						throw new Exception("unhandled unsubscribe option " . $options['unsubscribe']);
				}
				
				foreach($list_ids_to_remove as $id)
				{
					if(!in_array($id, $options['users_lists']))
					{
						pmprocc_deleteFromList($options['access_token'], $list_user->user_email, $id);
					}
				}
			}
		}
		else // we don't have a "users list" defined.
		{
			//some memberships are on lists. assuming the admin intends this level to be unsubscribed from everything
			//unsubscribe from any list not assigned to users
			if($options['unsubscribe'] !== UNSUB_NO)
			{
				switch($options['unsubscribe']) {
					case UNSUB_YES:
						$list_ids_to_remove = pmprocc_pluck('id', $all_lists);
						break;
					case UNSUB_MANAGED:
						$list_ids_to_remove = pmprocc_flatten(pmprocc_getSubarrayExcept($options, '', '^level_\\d+_lists$'));
						break;
					default:
						throw new Exception("unhandled unsubscribe option " . $options['unsubscribe']);
				}
				
				foreach($list_ids_to_remove as $id)
				{
					pmprocc_deleteFromList($options['access_token'], $list_user->user_email, $id);
				}
			}
		}
	}
}

//change email in Constant Contact if a user's email is changed in WordPress
function pmprocc_profile_update($user_id, $old_user_data)
{
	$new_user_data = get_userdata($user_id);
	if($new_user_data->user_email != $old_user_data->user_email)
	{			
		//get all lists
		$options = get_option("pmprocc_options");
		$api = new ConstantContact($options['api_key']);
		
		//$lists = $api->getLists($options['access_token']);
		
                $response = $api->contactService->getContacts($options['access_token'], array('email' => $old_user_data->user_email));
		$contact = $response->results[0];
				
		if(!empty($contact))
		{ 
			$contact->first_name = $new_user_data->first_name;
			$contact->last_name = $new_user_data->last_name;
			$contact->email_addresses[0]->email_address = $new_user_data->user_email;
                        $api->contactService->updateContact($options['access_token'], $contact, array('action_by'=> 'ACTION_BY_VISITOR'));
		}		
	}
}
add_action("profile_update", "pmprocc_profile_update", 10, 2);

//admin init. registers settings
function pmprocc_admin_init()
{
	//setup settings
	register_setting('pmprocc_options', 'pmprocc_options', 'pmprocc_options_validate');	
	add_settings_section('pmprocc_section_general', 'General Settings', 'pmprocc_section_general', 'pmprocc_options');	
	add_settings_field('pmprocc_option_api_key', 'Constant Contact API Key', 'pmprocc_option_api_key', 'pmprocc_options', 'pmprocc_section_general');
	add_settings_field('pmprocc_option_access_token', 'Constant Contact Access Token', 'pmprocc_option_access_token', 'pmprocc_options', 'pmprocc_section_general');		
	add_settings_field('pmprocc_option_users_lists', 'All Users List', 'pmprocc_option_users_lists', 'pmprocc_options', 'pmprocc_section_general');	
	add_settings_field('pmprocc_option_unsubscribe', 'Unsubscribe on Level Change?', 'pmprocc_option_unsubscribe', 'pmprocc_options', 'pmprocc_section_general');	
	
	//pmpro-related options	
	add_settings_section('pmprocc_section_levels', 'Membership Levels and Lists', 'pmprocc_section_levels', 'pmprocc_options');		
	
	//add options for levels
	pmprocc_getPMProLevels();
	global $pmprocc_levels;
	if(!empty($pmprocc_levels))
	{						
		foreach($pmprocc_levels as $level)
		{
			add_settings_field('pmprocc_option_memberships_lists_' . $level->id, $level->name, 'pmprocc_option_memberships_lists', 'pmprocc_options', 'pmprocc_section_levels', array($level));
		}
	}		
}
add_action("admin_init", "pmprocc_admin_init");

//set the pmprocc_levels array if PMPro is installed
function pmprocc_getPMProLevels()
{	
	global $pmprocc_levels, $wpdb;
	$pmprocc_levels = $wpdb->get_results("SELECT * FROM $wpdb->pmpro_membership_levels ORDER BY id");			
}

//options sections
function pmprocc_section_general()
{	
?>
<p></p>	
<?php
}

//options sections
function pmprocc_section_levels()
{	
	global $wpdb, $pmprocc_levels;
	
	//do we have PMPro installed?
	if(class_exists("MemberOrder"))
	{
	?>
		<p>PMPro is installed.</p>
	<?php
		//do we have levels?
		if(empty($pmprocc_levels))
		{
		?>
		<p>Once you've <a href="admin.php?page=pmpro-membershiplevels">created some levels in Paid Memberships Pro</a>, you will be able to assign Constant Contact lists to them here.</p>
		<?php
		}
		else
		{
		?>
		<p>For each level below, choose the lists which should be subscribed to when a new user registers.</p>
		<?php
		}
	}
	else
	{
		//just deactivated or needs to be installed?
		if(file_exists(dirname(__FILE__) . "/../paid-memberships-pro/paid-memberships-pro.php"))
		{
			//just deactivated
			?>
			<p><a href="plugins.php?plugin_status=inactive">Activate Paid Memberships Pro</a> to add membership functionality to your site and finer control over your Constant Contact lists.</p>
			<?php
		}
		else
		{
			//needs to be installed
			?>
			<p><a href="plugin-install.php?tab=search&type=term&s=paid+memberships+pro&plugin-search-input=Search+Plugins">Install Paid Memberships Pro</a> to add membership functionality to your site and finer control over your Constant Contact lists.</p>
			<?php
		}
	}
}


//options code
function pmprocc_option_access_token()
{
	$options = get_option('pmprocc_options');		
	if(isset($options['access_token']))
		$access_token = $options['access_token'];
	else
		$access_token = "";
	echo "<input id='pmprocc_access_token' name='pmprocc_options[access_token]' size='80' type='text' value='" . esc_attr($access_token) . "' />";
}


function pmprocc_option_api_key()
{
	$options = get_option('pmprocc_options');		
	if(isset($options['api_key']))
		$api_key = $options['api_key'];
	else
		$api_key = "";
	echo "<input id='pmprocc_api_key' name='pmprocc_options[api_key]' size='80' type='text' value='" . esc_attr($api_key) . "' />";
}



function pmprocc_option_users_lists()
{	
	global $pmprocc_lists;
	$options = get_option('pmprocc_options');
		
	if(isset($options['users_lists']) && is_array($options['users_lists']))
		$selected_lists = $options['users_lists'];
	else
		$selected_lists = array();
	
	if(!empty($pmprocc_lists))
	{
		echo "<select multiple='yes' name=\"pmprocc_options[users_lists][]\">";
		foreach($pmprocc_lists as $list)
		{
			echo "<option value='" . $list->id . "' ";
			if(in_array($list->id, $selected_lists))
				echo "selected='selected'";
			echo ">" . $list->name . "</option>";
		}
		echo "</select>";
	}
	else
	{
		echo "No lists found.";
	}	
}

function pmprocc_option_unsubscribe()
{
	$options = get_option('pmprocc_options');	
	?>
	<select name="pmprocc_options[unsubscribe]">
		<option value="<?php echo UNSUB_MANAGED ?>" <?php selected($options['unsubscribe'], 2);?>>Just those managed by PMPro Constant Contact
		<option value="<?php echo UNSUB_YES ?>" <?php selected($options['unsubscribe'], 1);?>>All</option>		
		<option value="<?php echo UNSUB_NO ?>" <?php selected($options['unsubscribe'], 0);?>>No</option>
	</select>
	<small>
		<ul>
		<li><b>No:</b> PMPro Constant Contact will not attempt to remove contacts from lists.</li>
		<li><b>Just those managed by PMPro Constant Contact:</b> <i>Recommended</i> Contacts will be removed from all lists that are associated with membership levels.</li>
		<li><b>Yes:</b> Contacts will be removed from every list in Constant Contact, even those not associated with membership levels.</li>
		</ul>
	</small>
	<?php
}

function pmprocc_option_memberships_lists($level)
{	
	global $pmprocc_lists;
	$options = get_option('pmprocc_options');
	
	$level = $level[0];	//WP stores this in the first element of an array
		
	if(isset($options['level_' . $level->id . '_lists']) && is_array($options['level_' . $level->id . '_lists']))
		$selected_lists = $options['level_' . $level->id . '_lists'];
	else
		$selected_lists = array();
	
	if(!empty($pmprocc_lists))
	{
		echo "<select multiple='yes' name=\"pmprocc_options[level_" . $level->id . "_lists][]\">";
		foreach($pmprocc_lists as $list)
		{
			echo "<option value='" . $list->id . "' ";
			if(in_array($list->id, $selected_lists))
				echo "selected='selected'";
			echo ">" . $list->name . "</option>";
		}
		echo "</select>";
	}
	else
	{
		echo "No lists found.";
	}	
}

// validate our options
function pmprocc_options_validate($input) 
{					
	//api key & access token
	$newinput['api_key'] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['api_key']));	
	$newinput['access_token'] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['access_token']));
	$newinput['unsubscribe'] = intval($input['unsubscribe']);
	
	//user lists
	if(!empty($input['users_lists']) && is_array($input['users_lists']))
	{
		$count = count($input['users_lists']);
		for($i = 0; $i < $count; $i++)
			$newinput['users_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['users_lists'][$i]));	;
	}
	
	//membership lists
	global $pmprocc_levels;		
	if(!empty($pmprocc_levels))
	{
		foreach($pmprocc_levels as $level)
		{
			if(!empty($input['level_' . $level->id . '_lists']) && is_array($input['level_' . $level->id . '_lists']))
			{
				$count = count($input['level_' . $level->id . '_lists']);
				for($i = 0; $i < $count; $i++)
					$newinput['level_' . $level->id . '_lists'][] = trim(preg_replace("[^a-zA-Z0-9\-]", "", $input['level_' . $level->id . '_lists'][$i]));	;
			}
		}
	}
	
	return $newinput;
}		

// add the admin options page	
function pmprocc_admin_add_page() 
{
	add_options_page('PMPro Constant Contact Options', 'PMPro Constant Contact', 'manage_options', 'pmprocc_options', 'pmprocc_options_page');
}
add_action('admin_menu', 'pmprocc_admin_add_page');

//html for options page
function pmprocc_options_page()
{
	//get options
	$options = get_option("pmprocc_options", array('api_key'=>'', 'access_token'=>'', 'unsubscribe'=>''));

	global $pmprocc_lists;
			
	//defaults
	if(empty($options))
	{
		$options = array("unsubscribe"=>UNSUB_MANAGED);
		update_option("pmprocc_options", $options);
	}
	elseif(!isset($options['unsubscribe']))
	{
		$options['unsubscribe'] = UNSUB_MANAGED;
		update_option("pmprocc_options", $options);
	}	
	
	//check for a valid API key and get lists
	if(!empty($options['api_key']))
		$api_key = $options['api_key'];
	if(!empty($options['access_token']))
		$access_token = $options['access_token'];

	if(!empty($api_key) && !empty($access_token))
	{
		$api = new ConstantContact($options['api_key']);

		try 
		{
                        $lists = $api->listService->getLists($options['access_token'], array());
		} 
		
		catch (CtctException $ex) 
		{
    			foreach ($ex->getErrors() as $error) 
    			{
					$msg = $error['error_message'];
					$msgt = "pmpro_error";					
    			}
    			
		}
		
		if(!empty($lists))
			$pmprocc_lists = $lists;								
		$all_lists = array();
		
		if(!empty($pmprocc_lists ))
		{
		
			//save all lists in an option
			$i = 0;			
			foreach ( $pmprocc_lists as $list )
			{
				$all_lists[$i]['id'] = $list->id;
				$all_lists[$i]['name'] = $list->name;
				$all_lists[$i]['contact_count'] = $list->contact_count;
				$all_lists[$i]['status'] = $list->status;
				$i++;
			}
		}
		
		//If we got here, there was an invalid APIKEY or ACCESS_TOKEN
		else
		{
			//Wordpress Error Message.
			$msg = sprintf( __( 'Sorry, but Constant Contact was unable to verify your API key or Access Token. Please try entering your API key and Access Token again.', 'pmpro-contantcontact' ), "" );
			add_settings_error( 'pmpro-constantcontact', 'apikey-fail', $msg, 'error' );
		}
	}
	else
		$all_lists = array();
	update_option( "pmprocc_all_lists", $all_lists);
?>
<div class="wrap">
	<div id="icon-options-general" class="icon32"><br></div>
	<h2>PMPro Constant Contact Integration Options</h2>		
	
	<?php if(!empty($msg)) { ?>
		<div class="pmpro_message <?php echo $msgt; ?>"><p><?php echo $msg; ?></p></div>
	<?php } ?>
	
	<form action="options.php" method="post">
		
		<p>This plugin will integrate your site with Constant Contact. You can choose one or more Constant Contact lists to have users subscribed to when they signup for your site.</p>
		<p>If you have <a href="http://www.paidmembershipspro.com">Paid Memberships Pro</a> installed, you can also choose one or more Constant Contact lists to have members subscribed to for each membership level.</p>
		<p>Don't have a Constant Contact account? <a href="http://www.constantcontact.com/index.jsp?pn=paidmembershipspro" target="_blank">Get one here</a>. It's free.</p>
		
		<?php settings_fields('pmprocc_options'); ?>
		<?php do_settings_sections('pmprocc_options'); ?>

		<p><br /></p>
						
		<div class="bottom-buttons">
			<input type="hidden" name="pmprot_options[set]" value="1" />
			<input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Save Settings'); ?>">				
		</div>
		
	</form>
</div>
<?php
}

/*
	Defaults on Activation
*/
function pmprocc_activation()
{
	//get options
	$options = get_option("pmprocc_options");	
	
	//defaults
	if(empty($options))
	{
		$options = array("unsubscribe"=>UNSUB_MANAGED);
		update_option("pmprocc_options", $options);
	}
	elseif(!isset($options['unsubscribe']))
	{
		$options['unsubscribe'] = UNSUB_MANAGED;
		update_option("pmprocc_options", $options);
	}
}
register_activation_hook(__FILE__, "pmprocc_activation");


function pmprocc_isMemberOfList(Contact $contact, $list_id)
{
	//error_log("PMPCC: contact's lists = " . print_r($contact->lists, true));
	foreach($contact->lists as $key => $value)
	{
		if($value->id == $list_id)
			return true;
	}
	
	return false;
}

function pmprocc_deleteFromList($access_token, $email, $list_id)
{
	
	try
	{
		//error_log("PMPCC: Deleting $email from list $list_id");
		
		//get options
		$options = get_option("pmprocc_options");	
		
		$api = new ConstantContact($options['api_key']);

		$response = $api->contactService->getContacts($access_token, array('email' => $email));

		if(!empty($response->results))
		{
			$contact = $response->results[0];

			if(pmprocc_isMemberofList($contact, $list_id)) {
                                $api->contactService->deleteContactFromList($access_token, $contact, $list_id);
			}
			else {
				//error_log("PMPCC: Email $email is not a member of list $list_id");
			}
		}
		else {
			//error_log("PMPCC: Email $email not found!");
		}
	}
	
	catch (CtctException $ex)
	{
		// Log the error.
		error_log("error deleting list from user: $ex");
	}

}

function pmprocc_getSubarrayExcept($input, $except_key, $search_regex = "")
{
	//error_log("PMPCC: GETSUBARRAYEXCEPT " . print_r($input, true) . ", except $except_key, search_regex $search_regex");
	$tmpkeys = array();
	$keys = array_keys($input);
	
	if ($search_regex === "") {
		$tmpkeys = $keys;
	}
	else {
		foreach ($keys as $k)
		{
			if (preg_match("/$search_regex/", $k) === 1) $tmpkeys[] = $k;
		}
	}
	$returnVal = array_intersect_key($input, array_flip($tmpkeys));
	if (array_key_exists($except_key, $returnVal)) unset($returnVal[$except_key]);

	return $returnVal;
}
function pmprocc_flatten(array $input) 
{ 
	$output = iterator_to_array(new RecursiveIteratorIterator(new RecursiveArrayIterator($input)), FALSE);
	return $output;
}
function pmprocc_pluck($key, $data) {
    return array_reduce($data, function($result, $array) use($key) {
        isset($array[$key]) && $result[] = $array[$key];
        return $result;
    }, array());
}

/*
Function to add links to the plugin action links
*/
function pmprocc_add_action_links($links) {	
	$new_links = array(
			'<a href="' . get_admin_url(NULL, 'options-general.php?page=pmprocc_options') . '">Settings</a>',
	);
	return array_merge($new_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pmprocc_add_action_links');

/*
Function to add links to the plugin row meta
*/
function pmprocc_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-constant-contact.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('http://www.paidmembershipspro.com/add-ons/free-add-ons/pmpro-constant-contact/') . '" title="' . esc_attr( __( 'View Documentation', 'pmpro' ) ) . '">' . __( 'Docs', 'pmpro' ) . '</a>',
                        '<a href="' . esc_url('http://www.constantcontact.com/index.jsp?pn=paidmembershipspro') . '" title="' . esc_attr( __( 'Constant Contact Signup', 'pmpro' ) ) . '">' . __( 'Constant Contact Signup', 'pmpro' ) . '</a>',
			'<a href="' . esc_url('http://paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmprocc_plugin_row_meta', 10, 2);
