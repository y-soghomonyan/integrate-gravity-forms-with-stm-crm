<?php
/*
Plugin Name: Integrate Gravity Forms with STM Corporate CRM System
Version: 1.0
Description: Extends the Gravity Forms plugin - collecting leads.
Author: Yervand Soghomonyan
Text Domain: integrate-gravity-forms-with-stm-crm
*/

add_action('init', array('GravityFormsStmCrmIntegration', 'init'));
class GravityFormsStmCrmIntegration{
    
    private $_user_name = 'EMAIL';
    
    private $_password = 'PASSWORD';

    private $_api_url = 'MY_API_URL';

    private $_token;
    
    
   
    public static function init()
    {
        $class = __CLASS__;
        new $class;
    }
    
    public $_fields = array(
        'title', 'first_name',  'last_name', 'company', 'phone', 'email', 'description', 'industry',
        'project_details', 'expected_annual_turnover', 'profit_expectation', 'interested_design',
        'desired_country', 'describe_company_profits', 'current_income_tax_subject','willing_relocate'
    );
    
    public function __construct()
    {
        add_filter( 'gform_pre_submission', array($this, 'check_post_params'), 10, 1 );
        add_filter( 'gform_form_settings', array($this, 'add_form_settings'), 20, 2 );
        add_filter( 'gform_pre_form_settings_save', array($this, 'save_form_settings'), 20, 2 );
    }
    
    public function save_form_settings($form)
    {
     
        $form['enable_crm_integration'] = rgpost( 'enable_crm_integration' );
        $form['source'] = rgpost( 'source' );
        $form['type'] = rgpost( 'type' );
      
        foreach($this->_fields as $field){
            $is_multiple = in_array($field, array('desired_country'));
            $form['crm_' . $field] = rgpost( 'crm_' . $field );
        }
        return $form;
    }
    
    
    public function create_field_options($form, $key)
    {
        $attrs = '';
        if($key ==='desired_country'){
            $name_attr = "crm_".$key."[]";
            $attrs = 'multiple';
        }else{
            $name_attr = "crm_".$key;
        }
        ?>
        <select name="<?= $name_attr; ?>" <?= $attrs?>>
            <option>Select Field</option>
             
            <?php foreach($form['fields'] as $field) {
                if(in_array($field->type, array('section', 'html', 'captcha', 'stripe_creditcard'))) {
                    continue;
                }
                $field_value = rgar($form, 'crm_' .$key);
                $is_current = is_array($field_value) ? in_array($field->id, $field_value) : $field_value == $field->id;
            ?>
                <option value="<?=$field->id; ?>"  <?= $is_current ? 'selected' : ''?> data-type="<?= $field->type?>"><?=$field->label?></option>
            <?php } ?>
        </select>
        <?php
    }



    public function add_form_settings( $settings, $form )
    {
        ob_start();
        ?>
        <tr>
            <th><label for="enable_crm_integration"><?= __("Enable CRM Integration", 'uscorp') ?></label></th>
            <td><input type="checkbox" name="enable_crm_integration" id="enable_crm_integration" value="yes" <?= rgar($form, 'enable_crm_integration') === 'yes' ? 'checked' : '' ?> ></td>
        </tr>
        
        <?php foreach($this->_fields as $field){ ?>
            <tr>
                <th><label  for="<?=$field?>"><?= ucfirst(str_replace('_', ' ', $field)) ?></label></th>
                <td>
                    <?php $this->create_field_options($form, $field); ?>
                </td>
            </tr>
        <?php } ?>
        
        <tr>
            
            <th><label for="source">Source</label></th>
            <?php $siteName = get_bloginfo( 'name' ); ?>
            <td><input type="text" name="source" id="source" value="<?= rgar($form,'source') == '' ? $siteName : rgar($form,'source') ?>" ></td>
        </tr>
        
        <tr>
            <th><label for="type"><?= __("Type", 'uscorp') ?></label></th>
            <td><input type="text" name="type" id="type" value="<?= rgar($form,'type') == '' ? 'General Contact Form' : rgar($form,'type');?>"></td>
        </tr>
        <?php
        $settings[ 'STM Corporate CRM Settings' ]['enable_crm_integration'] = ob_get_clean();

        return $settings;
    }
    
    
    
    public function check_post_params($form)
    {
     
        if(rgar($form, 'enable_crm_integration') === 'yes') {
     
            $token = $this->update_crm_token();
            
            $data = array();
            
            foreach($this->_fields as $field){
                $field_id = rgar( $form, 'crm_' . $field );
                $__field = GFAPI::get_field( $form, $field_id );
                $value = false;
                if($__field->type === 'checkbox') {
                    $value = $__field->get_value_submission( array() );
                    $value = array_values( array_filter( $value ) );
                    $data[$field] = serialize($value);
                }else {
                    if(!is_array($field_id)) {
                        $data[$field] = $_POST['input_'. $field_id] ?? '';    
                    } else {
                        foreach ($field_id as $id) {
                            if(isset($_POST['input_'. $id])){
                                $data[$field] = $_POST['input_'. $id] ?? '';    
                            }
                        }
                    } 
                }
            }
            $data['source'] = $form['source'];
            $data['type'] = $form['type'];
            $data['http_referer'] = $_SERVER['HTTP_REFERER'] ?? '';
            
            $endpoint = $this->_api_url . '/leads/gform/create';
            $body = json_encode( $data );
    
            $options = [
                'method'=>'POST',
                'body' => $body,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization'=>'Bearer '.$token
                ],
            ];
            
            $response = wp_remote_request( $endpoint, $options );

            if ( is_wp_error( $response ) ) {
                $error_message = $response->get_error_message();
                echo "Something went wrong: $error_message";
            } 
        }
    }

    public function update_crm_token()
    {
        
        $endpoint = $this->_api_url . '/login';
          
        $data = array();
         
        $data['email'] = $this->_user_name;
        $data['password'] = $this->_password;
        $body = json_encode($data);
          
        $options = [
            'method'=>'POST',
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json'
            ],
        ];
            
        $response = wp_remote_request( $endpoint, $options );
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            echo "Something went wrong: $error_message";
        }

        $res_body = json_decode($response['body']);
        $token = $res_body->token;

        $result = array();
        return $result['token']=$token;
    }
    
    
    
    public function dump(...$vars) 
    {
        echo "<pre>";
        var_dump(...$vars);
        echo "</pre>";
    }
}