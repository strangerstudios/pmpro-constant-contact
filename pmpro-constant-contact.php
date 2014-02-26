<?php
/*
Plugin Name: PMPro Constant Contact Integration
Plugin URI: http://www.paidmembershipspro.com/pmpro-constantcontact/
Description: Sync your WordPress users and members with Constant Contact lists.
Version: 1.0
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

//init
function pmprocc_init()
{
	//include ConstantContact Class if we don't have it already
	require_once dirname(__FILE__) . '/includes/Ctct/autoload.php';

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
	clean_user_cache($user_id);
	
	$options = get_option("pmprocc_options");
	
	//should we add them to any lists?
	if(!empty($options['users_lists']) && !empty($options['api_key']))
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
        			$response = $api->getContactByEmail($options['access_token'], $list_user->user_email);

		        	// create a new contact if one does not exist
        			if (empty($response->results))
        			{
            			$contact = new Contact();
						$contact->addEmail($list_user->user_email);
						$contact->addList($list->id);
						$contact->first_name = $list_user->first_name;
						$contact->last_name = $list_user->last_name;
						$api->addContact($options['access_token'], $contact); 
					}
        		}
        		
        		//Catch any errors so the user can't see them.
        		catch (CtctException $ex)
        		{
        			//Add a WordPress Error Message?
        		}
	
		}
	}
}

//subscribe new members (PMPro) when they register
function pmprocc_pmpro_after_change_membership_level($level_id, $user_id)
{
	clean_user_cache($user_id);
	
	global $pmprocc_levels;
	$options = get_option("pmprocc_options");
	$all_lists = get_option("pmprocc_all_lists");	
		
	//should we add them to any lists?
	if(!empty($options['level_' . $level_id . '_lists']) && !empty($options['api_key']))
	{
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
				// check to see if a contact with the email addess already exists in the account
				$response = $api->getContactByEmail($options['access_token'], $list_user->user_email);
				
				//?? What should we do if the email address already exists?
				
				// create a new contact if one does not exist
				if (empty($response->results))
				{
					$contact = new Contact();
					$contact->addEmail($list_user->user_email);
					$contact->addList($list);
					$contact->first_name = $list_user->first_name;
					$contact->last_name = $list_user->last_name;
					$api->addContact($options['access_token'], $contact); 
				}      			
				else
				{
					$contact = $response->results[0];
					$contact->addList($list);
					$contact->first_name = $list_user->first_name;
					$contact->last_name = $list_user->last_name;
					$returnContact = $api->updateContact($options['access_token'], $contact, false);
				}
      			
        
       		}        	
			//Catch any errors so the user can't see them.
			catch (CtctException $ex)
			{
				//WordPress Error Message?
			}
		}
		
		//unsubscribe them from lists not selected
		if($options['unsubscribe'])
		{
			foreach($all_lists as $list)
			{
				if(!in_array($list[id], $options['level_' . $level_id . '_lists']))
				{
					deleteFromList($options['access_token'], $list_user->user_email, $list[id]);
				}
			}
		}
	}
	elseif(!empty($options['api_key']) )
	{
		//now they are a normal user should we add them to any lists?
		if(!empty($options['users_lists']) && !empty($options['api_key']))
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
					$response = $api->getContactByEmail($options['access_token'], $list_user->user_email);

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
						
						$api->addContact($options['access_token'], $contact, true); 
					}	
				}			
				//Catch any errors so the user can't see them.
				catch (CtctException $ex)
				{
					//WordPress Error Message?
				}						
			}
			
			//unsubscribe from any list not assigned to users
			if($options['unsubscribe'])
			{
				foreach($all_lists as $list)
				{
					if(!in_array($list[id], $options['users_lists']))
					{
						deleteFromList($options['access_token'], $list_user->user_email, $list[id]);
					}	
				}
			}
		}
		else
		{
			//some memberships are on lists. assuming the admin intends this level to be unsubscribed from everything
			if($options['unsubscribe'])
			{
				if(is_array($all_lists))
				{
					//get user info
					$list_user = get_userdata($user_id);
					
					//unsubscribe to each list
					$api = new ConstantContact($options['api_key']);
					foreach($all_lists as $list)
					{
						deleteFromList($options['access_token'], $list_user->user_email, $list[id]);
					}					
				}
			}
		}
	}
}

//change email in Constant Contact if a user's email is changed in WordPress
function pmprocc_profile_update($user_id, $old_user_data)
{
	$new_user_data = get_userdata($user_id);
	if(true || $new_user_data->user_email != $old_user_data->user_email)
	{			
		//get all lists
		$options = get_option("pmprocc_options");
		$api = new ConstantContact($options['api_key']);
		
		$lists = $api->getLists($options['access_token']);
		
		$response = $api->getContactByEmail($options['access_token'], $old_user_data->user_email);
		$contact = $response->results[0];
				
		if(!empty($lists) && !empty($contact))
		{ 
			foreach($lists as $list)
			{
				$contact->addList($list->id);
				$contact->first_name = $new_user_data->first_name;
				$contact->last_name = $new_user_data->last_name;
				$contact->email_addresses[0]->email_address = $new_user_data->user_email;
								
				//filter
				$custom_fields = apply_filters("pmpro_constant_contact_custom_fields", array(), $new_user_data);
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
						
				$api->updateContact($options['access_token'], $contact, true);
			}		
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
		<option value="0" <?php selected($options['unsubscribe'], 0);?>>No</option>
		<option value="1" <?php selected($options['unsubscribe'], 1);?>>Yes</option>		
	</select>
	<small>Recommended: Yes. However, if you manage multiple lists in Constant Contact and have users subscribe outside of WordPress, you may want to choose No so contacts aren't unsubscribed from other lists when they register on your site.</small>
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
	$options = get_option("pmprocc_options");	

	$api = new ConstantContact($options['api_key']);

	global $pmprocc_lists;
			
	//defaults
	if(empty($options))
	{
		$options = array("unsubscribe"=>1);
		update_option("pmprocc_options", $options);
	}
	elseif(!isset($options['unsubscribe']))
	{
		$options['unsubscribe'] = 1;
		update_option("pmprocc_options", $options);
	}	
	
	//check for a valid API key and get lists
	$api_key = $options['api_key'];
	$access_token = $options['access_token'];
	
	if(!empty($api_key) && !empty($access_token))
	{
		try 
		{
    			$lists = $api->getLists($options['access_token']);
		} 
		
		catch (CtctException $ex) 
		{
    			foreach ($ex->getErrors() as $error) 
    			{
					$msg = $error['error_message'];
					$msgt = "pmpro_error";					
    			}
    			
		}
		
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
			add_settings_error( 'pmpro-constantcontact', 'apikey-fail', $message, 'error' );
		}
	}
	
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
		<p>Don't have a Constant Contact account? <a href="http://www.constantcontact.com/features/signup.jsp?pn=strangerstudios" target="_blank">Get one here</a>. It's free.</p>
		
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
		$options = array("unsubscribe"=>1);
		update_option("pmprocc_options", $options);
	}
	elseif(!isset($options['unsubscribe']))
	{
		$options['unsubscribe'] = 1;
		update_option("pmprocc_options", $options);
	}
}
register_activation_hook(__FILE__, "pmprocc_activation");


function isMemberOfList(Contact $contact, $list_id)
{
	
	
	foreach($contact->lists as $key => $value)
	{
		if($value->id == $list_id)
			return true;
	}
	
	return false;

}

function deleteFromList($access_token, $email, $list_id)
{
	
	try
	{
		//get options
		$options = get_option("pmprocc_options");	
		
		$api = new ConstantContact($options['api_key']);

		$response = $api->getContactByEmail($access_token, $email);

		if(!empty($response->results))
		{
			$contact = $response->results[0];

			if(isMemberofList($contact, $list_id))
				$api->deleteContactFromList($access_token,$contact, $list_id);
		}
	}
	
	catch (CtctException $ex)
	{
       	//Add a WordPress Error Message?
	}

}
