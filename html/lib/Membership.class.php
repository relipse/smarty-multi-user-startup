<?PHP
    /**
     * This file contains the Membership class which
     * contains functions that deal with creating organizations/users
     */
     
    require_once(dirname(__FILE__).'/config.inc.php');
    require_once(dirname(__FILE__).'/site.inc.php');

    class Membership
    {
       public static function SessionStart($opts = array()){
          session_start();
          if (!is_array($opts)){ $opts = array(); }
          
          $default_opts = array('login'=>'FORCE',
                                //the page the browser goes to if it fails
                                'onfailure-redirect'=>'login.php?goto='.urlencode($_SERVER['HTTP_REFERER']) //go to the login page on a failure
                          );
          
          $opts = array_merge($default_opts, $opts);
          
          switch(strtoupper($opts['login'])){
             case 'ALLOW':
                 //do nothing, session_start() was already called
             break;
             case 'ADMIN-ONLY':
                 //force an admin user only
                 if (self::IsLoggedIn() && $_SESSION['user']['username'] == 'admin'){
                     return true;
                 }else{
                     if (!empty($opts['onfailure-redirect'])){ 
                        header('Location: '.$opts['onfailure-redirect']);
                        exit;
                     }else{
                        return false;
                     }                    
                 }
             break;
             case 'FORCE':
                if (!self::IsLoggedIn()){
                  if (!empty($opts['onfailure-redirect'])){ 
                     header('Location: '.$opts['onfailure-redirect']);
                     exit;
                  }else{
                     return false;
                  }
                }
                else {
                  return true;
                }
             break;
          }
       
       }
    
      /** 
       * Perform a login, thus adding the user to the SESSION
       * And also creating a user token
       */
      public static function Login($org_login, $username, $password)
      {
         site::log('LOGINATTEMPT', $org_login.'@'.$username);
         
         $sql = "SELECT * FROM organizations WHERE LOWER(login) = LOWER(:org_login)";
         $prep = site::getPDO()->prepare($sql);
         $prep->execute(array(':org_login'=>$org_login));
         $org = $prep->fetch(PDO::FETCH_ASSOC);
         if (!$org){ return self::CreateError('org_login', 'INVALIDORG', 'The organization does not exist'); }

         if (strpos($username, '@') !== false){
            //match by email address
            $email = $username;
            $sql = "SELECT * FROM email WHERE LOWER(email) = LOWER(:email)";
            $prep = site::getPDO()->prepare($sql);
            $prep->execute(array(':email'=>$email));
            $user = $prep->fetch(PDO::FETCH_ASSOC);
            if (!$user){ return self::CreateError('username', 'INVALIDUSER', 'The email does not exist within this organization'); } 
         }else{ //match by username
            $sql = "SELECT * FROM users WHERE LOWER(username) = LOWER(:username)";
            $prep = site::getPDO()->prepare($sql);
            $prep->execute(array(':username'=>$username));
            $user = $prep->fetch(PDO::FETCH_ASSOC);
            if (!$user){ return self::CreateError('username', 'INVALIDUSER', 'The username does not exist within this organization'); }
         }
         
         if (!self::VerifyHashedPassword($password, $user['password'])){
            return self::CreateError('password', 'INVALIDPASSWORD', 'The password provided was incorrect');
         }
         //login credentials were correct, next step is to modify the $_SESSION to show that we are logged in
         $_SESSION['loggedin'] = time();
         $_SESSION['org'] = $org;
         $_SESSION['user'] = $user;
         $token = misc::getRandomAlphaNumericString(40);
         $sql = "INSERT INTO tokens (token, ip, organization_id, user_id, date_created, date_accessed)
                              VALUES(:token, :ip, :org_id, :user_id, NOW(), NOW());";
         $prep = site::getPDO()->prepare($sql); 
         $prep->execute(array(':token'=>$token, 
                                 ':ip'=>$_SERVER['REMOTE_ADDR'], 
                             ':org_id'=>$org['id'], 
                            ':user_id'=>$user['id']));
         if ($prep->rowCount() == 0){
            return self::CreateError('','INSERTTOKENFAILURE', 'There could possibly be a duplicate token (yeah right the chances of that are like 1 in 500 billion');
         }
         $_SESSION['token'] = $token; 

         //go ahead and update the access token
         self::UpdateAccessToken();

         
         return true;
      }
      
      /** 
       * Logout of a user's session, the current logged in user, 
       * or a specific user given their token
       * @return integer the number of tokens deleted
       */
      public static function Logout($token = null){
         session_start(); //does nothing if already started
         $destroysession = true;
         if (empty($token)){ 
            if (isset($_SESSION['token'])){
               $token = $_SESSION['token'];
            }else{
               return false; //could not destroy empty token
            }
         }else{
           //a token was supplied therefore we won't destroy the session
           $destroysession = false;
         }
         
         if ($destroysession){
            session_unset();
            session_destroy();
            $_SESSION = array();
         }
         
         if (!empty($token)){
            $sql = "DELETE FROM tokens WHERE token = :token";
            $prep = site::getPDO()->prepare($sql);
            echo $prep->execute(array(':token'=> $token));
            return $prep->rowCount();
         }
         return 0; //no tokens modified
      }
      
      /**
       * Update the access token with the current time and current page
       * @see LOGIN_EXPIRE_INTERVAL
       * @return bool false if token not found, user_id and organization doesnt match, or access has expired
       */
      public static function UpdateAccessToken(){
            if (empty($_SESSION['token'])){ return false; }
            
            $sql = "UPDATE tokens SET date_accessed = NOW(), microtime_accessed = :microtime, page_accessed = :curpage
                    WHERE token = :token 
                      AND organization_id = :org_id 
                      AND user_id = :user_id
                      AND NOW() <= date_accessed + ".LOGIN_EXPIRE_INTERVAL;
                      
            $prep = site::getPDO()->prepare($sql);
            $prep->execute(array(':token'=>$_SESSION['token'], 
                                 ':microtime'=> microtime(true), 
                                 ':curpage'=> $_SERVER['REQUEST_URI'],
                                 ':org_id'=>$_SESSION['org']['id'],
                                 ':user_id'=>$_SESSION['user']['id'])
                          );
            //we included microtime to ensure that a value always gets updated when this runs, if this fails then the token has expired
            //if nothing was found, the token could be invalid
            return ($prep->rowCount() === 1);    
      }
      
      
       /** 
        * Check whether the user is logged in to the session AND that the user and organization exist
        * And check if the token is valid
        * @return true on user logged in, false on failure
        */
       public static function IsLoggedIn(){
          return !empty($_SESSION['org']['id'])  && 
                 !empty($_SESSION['user']['id']) && 
                 !empty($_SESSION['token'])   && 
                 self::UpdateAccessToken() === true; 
       }
       
       
       /** 
        *  Make sure the password doesn't contain spaces and is 6 to 18 
        *  digits long
        *  @return bool true on valid, false on invalid
        */
       public static function ValidPassword($password){
          return preg_match('/\\A[^\\s]{6,18}\\z/', $password);
       }
    
       /**  
        * Use bcrypt to hash a password
        * Beware, every time CRYPT_ITERATIONS changes, 
        * ALL existing passwords will break
        * @see http://codahale.com/how-to-safely-store-a-password/
        * @return string the hashed password to be stored in database
        */
       private static function HashPassword($password, $rounds = null){
            if (is_null($rounds)){ $rounds = CRYPT_ITERATIONS; }
            require_once(dirname(__FILE__).'/BCrypt.class.php');
            $bcrypt = new Bcrypt($rounds);
            return $bcrypt->hash($password);
       }
       
       /**
        * Check to see if the password matches the hash using HashPassword()
        * Warning, if CRYPT_ITERATIONS changes, the only way to recover existing passwords is to verify against 
        * the previous $rounds value
        * @see self::HashPassword()
        * @return bool true on match, false on mismatch
        */
       private static function VerifyHashedPassword($password, $hash, $rounds = null)
       {
            if (is_null($rounds)){ $rounds = CRYPT_ITERATIONS; }
            require_once(dirname(__FILE__).'/BCrypt.class.php');
            $bcrypt = new Bcrypt($rounds);
            return $bcrypt->verify($password, $hash);      
       }
       
       
       /** 
        * Hash a password (or something else) using crypt() with SALT and iterations
        * @return string encrypted/hashed password
        */
       private static function OldHashPassword($password, $iterations = null){
            if (is_null($iterations) || !$iterations){ $iterations = CRYPT_ITERATIONS; }
            $hash = crypt($password, CRYPT_SALT);
            for ($i = 0; $i < $iterations; ++$i)
            {
                $hash = crypt($hash . $password, CRYPT_SALT);
            }
            return md5($hash);
       }
       
       /**
        * Search for an organization/company by their shortname (login)
        * @return bool|array false on failure, array of organization on success
        */
       public static function FindOrganizationByLogin($org_login)
       {
          $sql = "SELECT * FROM organizations WHERE LOWER(login) = LOWER(:org_login)";
          $prep = site::getPDO()->prepare($sql);
          $prep->execute(array(':org_login' => $org_login));
          return $prep->fetch();
       }
       
       
       /** 
        * Create a common error array used by php pages normally to display errors to the user
        * @return array the error array with the form_field affected, the shortname of the error, and the error message
        */
       private static function CreateError($form_field, $shortname, $message)
       {
          return array('form_field'=>$form_field, 'shortname'=>$shortname, 'message'=>$message);
       }
       
       
       /**
        * Check if the organization login matches a username type login regular expression
        * This does NOT check for duplicates in the database, for that use FindOrganizationByLogin()
        * @return bool on success return true, on failure return false
        */
       public static function ValidOrgLogin($org_login){
          return preg_match('/\\A[a-z][_a-z\\d]+[a-z\\d]\\z/i', $org_login);
       }
       
       
       
       /** 
        * Insert a new organization into the database (need to also call CreateUser to complete registration)
        * @return integer|array newly inserted organization id on success, otherwise array with the error field and message
        */
       public static function CreateOrganization($org_login, $org_name, $plan_shortname_or_id)
       {
            if (!self::ValidOrgLogin($org_login)) {
               return self::CreateError('org_login', 'org_login_match_failure', 
                      'Organization login must start with a letter, end with a letter or digit, and contain letters, digits, or underscores in between.');
            } 
            
            if (self::FindOrganizationByLogin($org_login)){
               return self::CreateError('org_login', 'duplicate_org', 'Duplicate organization/company exists by this login: '.$org_login);
            }
            
            //find plan
            if (is_numeric($plan_shortname_or_id)){
                $sql = 'SELECT * FROM plans WHERE id = :plan_id LIMIT 1';
                $prep = site::getPDO()->prepare($sql);
                $prep->execute(array(':plan_id' => (int)$plan_shortname_or_id));
            }else{
                $sql = 'SELECT * FROM plans WHERE shortname = :shortname LIMIT 1';
                $prep = site::getPDO()->prepare($sql);
                $prep->execute(array(':shortname' => $plan_shortname_or_id));              
            }
             
            $plan = $prep->fetch();
            if (empty($plan)){
               return self::CreateError('plan', 'plan_invalid', $plan_shortname_or_id.' is not a valid plan');
            }
            $date_expires = null;
            if ($plan['charge_cycle'] == 'yearly'){
               $date_expires = date("Y-m-d H:i:s", strtotime('+1 year'));
            }
            $sql = 'INSERT INTO organizations (login, name, plan_id, date_created, date_expires) VALUES (:login, :name, :plan_id, NOW(), NOW() + INTERVAL 30 day)';
            $prep = site::getPDO()->prepare($sql);
            if (!$prep->execute(array(':login'=>$org_login, ':name'=>$org_name,':plan_id' => (int)$plan['id']))){
              return self::CreateError('', 'db_insert_org_failure', $prep->errorInfo());
            }
            $org_id = site::getPDO()->lastInsertId();
            site::log('CREATEORGANIZATION', $org_login.'(org_id: '.$org_id.') '.$org_name.' on plan '.$plan['shortname']);
            return $org_id;
       }
       
       
       /** 
        * Ensure $org_login is available, if not, append a digit and check again. Repeat.
        *
        * Basically, we will add numbers to the org_login if it is already taken
        * For example if $org_login is 'mycompany' and 'mycompany' is already taken, 
        * this function will try 'mycompany2', and if that is taken, then 'mycompany3'.
        * (you get the point) This function fails when iterating too many times (too many duplicates)
        *  It is up to the programmer to call ValidOrgLogin() before and after to ensure the login 
        *  name meets the requirements before inserting it into the database. (low coupling allows us to modify
        *   the validity where it needs to be validated, not here)
        *  If per-chance another user registers, the login name might be taken after this function call. 
        *  (not sure how PHP handles asynchronous transactions)
        *
        * @return string|bool the resulting org_login that is available, or false on failure 
        */
       public static function GetNextAvailableOrganizationLogin($org_login)
       {
           $org_login_result = $org_login;
           $too_many_tries_count = 25;
           $suffix_num = 2;
           while (($org = self::FindOrganizationByLogin($org_login_result)) && $suffix_num < $too_many_tries_count){
                $org_login_result = $org_login.$suffix_num;
                $suffix_num++;
           }           
           
           if (empty($org)){
              return $org_login_result;
           }else{
              return false;
           }
       }
       
       
       /** 
        * Get a plan by the shortname or id
        * @return mixed plan array on success, whatever pdo returns on failure
        */
       public static function FetchPlan($plan_shortname_or_id)
       {
            $shortname_or_id = is_numeric($plan_shortname_or_id) ? 'id' : 'shortname';
            $sql = 'SELECT * FROM plans  WHERE '.$shortname_or_id.' = :plan LIMIT 1';
            $prep = site::getPDO()->prepare($sql);
            $prep->execute(array(':plan' => strtolower($plan_shortname_or_id)));
            return $prep->fetch();       
       }
       
       /** 
        * Check if user entered at least a first and last name
        * @return bool true on name at least 2 words, false on failure
        */
       public static function ValidFullName($full_name)
       {
          //at least one space and one letter
          return (preg_match('/\\w \\w/', $full_name));
       }
       
       /** 
        * Check if the organization name is valid
        * That is, it contains at least 3 non-space characters
        * @return bool true on valid organization name, false on failure
        */
       public static function ValidOrgName($org_name)
       {    
          //an organization can have spaces, but it should have at least 3 letters
          return preg_match('/\\w\\w\\w/', $org_name);
       }
       
       /** 
        * Create a new account (based on register.php)
        * The tricky part is we have to generate by educated guess the organization login
        * And not only that, but we have to ensure it is valid and not already taken
        *
        * @see CreateOrganization()
        * @see CreateUser()
        */
       public static function NewAccount($plan, $full_name, $org_name, $email, $password)
       {
            if (!self::FetchPlan($plan)){
              return self::CreateError('plan', 'invalid_plan', 'This plan does not exist. Please choose a plan');               
            }

            if (!self::ValidFullName($full_name)){
              return self::CreateError('name', 'invalid_fullname', 'Please include your first and last name');               
            }
            
            if (!self::ValidOrgName($org_name)){
              return self::CreateError('org_name', 
                   'invalid_org_name', 
                   'An organization name must contain at least 3 letters');
            }
            
            $org_login = strtolower(preg_replace('/^\\d|[^\\w]/', '', preg_replace('/[\\s-]/', '_', $org_name)));
            if (!self::ValidOrgLogin($org_login)) {
               //if we get here, something is wrong with the above line ($org_login = strtolower...)
               //the programmer should be notified to always generate syntactically valid login names
               return self::CreateError('org_login', 'org_login_match_failure', 
                      'Organization login must start with a letter, end with a letter or digit, and contain letters, digits, or underscores in between.');
            } 
            
            //come up with an available org_login
            $available_org_login = self::GetNextAvailableOrganizationLogin($org_login);
            
            if ($available_org_login === false){
               return self::CreateError('org_login', 'duplicate_org', 'Duplicate organization/company exists by this login: '.$valid_org_login);
            }

            if (!self::ValidOrgLogin($available_org_login)) {
               //if we get here, something is wrong with the GetNextAvailableOrganizationLogin() function
               return self::CreateError('org_login', 'org_login_match_failure', 
                      'Organization login must start with a letter, end with a letter or digit, and contain letters, digits, or underscores in between.');
            } 

           $org_login = $available_org_login;
           
           site::log('NEWACCOUNTATTEMPT', $plan.', '.$full_name.', '.$org_name.', '.$email.', generated login name: '.$org_login);
           
           $org_id = Membership::CreateOrganization($org_login, $org_name, $plan);
           if (is_array($org_id)){
                $error = $org_id;
                return $error;
           }else{
                $user_id = Membership::CreateUser($org_id,'admin', $password, $email, $full_name);
                if (is_numeric($user_id)){
                    //by the way, an email was already sent to the user inside CreateUser() method
                    return true; //user was created successfully (and organization)
                }else{
                    $error = $user_id;
                    return $error;
                }
           }        
       }
       
       
       /**
        * Insert a new user into the database, must have a pre-existing organization
        * @return integer|array newly inserted user id on success, otherwise array with error message info
        */
       public static function CreateUser($org_id, $username, $password, $email, $name = ''){
                $org_id = (int)$org_id;
                $sql = 'SELECT * FROM organizations JOIN plans ON organizations.plan_id = plans.id WHERE organizations.id = :org_id LIMIT 1';
                $prep = site::getPDO()->prepare($sql);
                $prep->execute(array(':org_id' => $org_id));
                $org = $prep->fetch();
                if (empty($org)){
                   return self::CreateError('org_id', 'org_invalid', 'Invalid Company/Organization');
                }
                $sql = 'SELECT COUNT(*) FROM users WHERE organization_id = :org_id';
                $prep = site::getPDO()->prepare($sql);
                $prep->execute(array(':org_id' => $org_id));
                $count = $prep->fetch();
                $count = $count['COUNT(*)'];
                
                if ($count + 1 > $org['max_users']){
                   return self::CreateError('','plan_max_users_violation', 'Only '.$org['max_users'].' users allowed.');
                }           
                if (!preg_match('/\\A[a-z][_a-z\\d]+[a-z\\d]\\z/i', $username)) {
                   return self::CreateError('username','username_match_failure', 'Username must start with a letter, end with a letter or digit, and contain letters, digits, or underscores in between.');
                }
                if (strpos($username, '__') !== false){
                   return self::CreateError('username', 'username_double_underscore', 'Username cannot contain 2 underscores in a row');
                }
                if (strlen($username) < 3){
                   return self::CreateError('username', 'username_too_short', 'Username is less than 3 characters');
                }else if (strlen($username) > 15){
                   return self::CreateError('username', 'username_too_long', 'Username is more than 15 characters');
                }
                
                if (!strpos($email, '@') >= 1){
                   return self::CreateError('email', 'email_invalid', 'Email address is invalid');
                }
                
                $sql = 'SELECT username FROM users WHERE LOWER(username) = LOWER(:username) AND organization_id = :org_id';
                $prep = site::getPDO()->prepare($sql);
                $prep->execute(array(':username' => $username, ':org_id'=> $org_id));
                $users = $prep->fetchAll();
                
                if (!empty($users)){ return self::CreateError('username', 'username_taken', 'Username already taken'); }
                
                $sql = 'SELECT email FROM users WHERE LOWER(email) = LOWER(:email) AND organization_id = :org_id';
                $prep = site::getPDO()->prepare($sql);
                $prep->execute(array(':email' => $email, ':org_id'=> $org_id));
                $users = $prep->fetchAll();
                
                if (!empty($users)){ return self::CreateError('email', 'email_taken', 'Email already taken'); }
                
                if (!self::ValidPassword($password)) {
                   return self::CreateError('password', 'password_match_failure', 'Password must be between 6 and 18 characters and contain no spaces');
                }
                
                
                //get a code (for now just use the hash function with a bunch of data 
                //using a different number of iterations than the normal hash (to prevent hackers from using the verify code info)
                $code = self::OldHashPassword($org_id.$org['login'].$username.$email, 1); 
                $verify_link = 'http://www.clearbugs.com/verify.php?email='.urlencode($email).'&code='.urlencode($code);
                
                require_once 'lib/phpmailer/class.phpmailer.php';
                $mail = new PHPMailer(true); //defaults to using php "mail()"; the true param means it will throw exceptions on errors, which we need to catch

                try {
                  $mail->AddAddress($email, $name);
                  $mail->SetFrom('noreply@clearbugs.com', 'ClearBugs');
                  $mail->AddReplyTo('support@clearbugs.com', 'ClearBugs');
                  $mail->Subject = 'Clearbugs Registration Confirmation';
                  $s = site::getSmarty();
                  $s->assign('verify_link', $verify_link);
                  $email_html = $s->fetch('register_email_confirm.tmpl');
                  //$mail->AltBody = // optional - MsgHTML will create an alternate automatically
                  $mail->MsgHTML($email_html);
                  $mail->AddAttachment('lib/skin/images/bugkiller.png');      // attachment
                  $mail->Send();
                } catch (phpmailerException $e) {
                  return self::CreateError('email', 'mail_error', $e->errorMessage()); //Pretty error messages from PHPMailer
                } catch (Exception $e) {
                  return self::CreateError('email','exception', $e->getMessage()); //Boring error messages from anything else!
                }
                
                 
                $hashed_password = self::HashPassword($password);
                
                //email was successfully sent, go ahead and insert it into the database now
                $sql = "INSERT INTO users (id, organization_id, username, password, email, email_verified, 
                                           phone, name, street1, street2, city, state, zip, country, date_added, date_updated,
                                           verify_code, verify_action, verify_param, verify_date_expires) VALUES 
                                          (NULL, :org_id, :username, :hashed_password, :email, 0,
                                           '', :name, '', '', '', '', '', '', NOW(), NOW(), 
                                           :verify_code, 'initial_email_verification', :email, NOW() + INTERVAL 24 hour)";
                $prep = site::getPDO()->prepare($sql);
                $res = $prep->execute(array(':org_id'=> $org_id, 
                                     ':username' => $username, 
                                     ':hashed_password'=>$hashed_password,
                                     ':email'=> $email,
                                     ':name'=> $name,
                                     ':verify_code'=>$code
                                    ));
                if (!$res){
                   return self::CreateError('','db_insert_user_failure',$prep->errorInfo());
                }
                site::log('CREATEUSER', $username.' at '.$org['login'].'(org_id: '.$org_id.')');
                return site::getPDO()->lastInsertId();
        }
    }
?>