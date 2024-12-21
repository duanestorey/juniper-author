<div class="wrap juniper">
    <h1><?php esc_html_e( 'Author/Options', 'juniper' ); ?></h1>

    <p>
        <?php esc_html_e( 'You can configure the options for Juniper/Author here.', 'juniper' ); ?><br />


    </p>

    <?php $this->doOptionsHeader(); ?>

    <?php if ( $this->getSetting( 'private_key' ) ) { ?>
        <form method="post" action="options-general.php?page=api-privacy"> 
            <input type="hidden" name="juniper_author" value="1">
            <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">
            <table class="form-table" role="presentation">
                <tbody>
                    <?php foreach( $this->settingsPages[ 'options' ] as $name => $data ) { ?>
                        <tr>
                            <th><?php echo esc_html( $data[ 0 ] ); ?></th>
                            <td>
                                <fieldset>
                                    <?php foreach( $data[ 1 ] as $setting ) { ?>
                                        <?php $this->renderOneSetting( $setting ); ?>
                                    <?php } ?>
                                </fieldset>
                            </td>
                        </tr>
                    <?php }?> 
                </tbody>
            </table>
            <input type="submit" id="submit" class="button button-primary" name="submit" value="<?php esc_attr_e( 'Save Changes', 'juniper' ); ?>" />
        </form> 
    <?php } else { ?>
        <form method="post" action="admin.php?page=juniper-options"> 
            <input type="hidden" name="juniper_author_gen_keypair" value="1">
            <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th><?php _e( 'Private Key Generation', 'juniper' ); ?></th>
                        <td>
                            <p><?php _e( 'Juniper requires a private cryptographic key to sign all releases. To create a private key pair, enter a password below and click generate. ', 'juniper' ); ?></p>
                            
                            <p><?php _e( 'This password will be required to sign all releases, and is never saved.', 'juniper' ); ?></p><br />
   
                            <fieldset>
                                <div class="one-setting">
                                    <?php _e( 'Enter password to protect private key', 'juniper' ); ?></label><br/>
                                    <input type="password" name="juniper_private_pw_1" />  <label for="juniper_private_pw_1">
                                    
                                </div>
                                <div class="one-setting">
                                    <label for="juniper_private_pw_1"><?php _e( 'Confirm password to protect private key', 'juniper' ); ?></label><br/>
                                    <input type="password" name="juniper_private_pw_2" />
                                </div>
                            </fieldset>
                        </td>
                    </tr>
                </tbody>
            </table>
            <input type="submit" id="submit" class="button button-primary" name="submit" value="<?php esc_attr_e( 'Generate Key Pair', 'juniper' ); ?>" />
        </form>      
    <?php } ?>

</div>
