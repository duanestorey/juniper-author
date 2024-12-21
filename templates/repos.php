<div class="wrap juniper">
    <h1><?php esc_html_e( 'Author/Releases', 'juniper' ); ?></h1>

            <?php
        
                $curves = openssl_get_curve_names();

                print_r( $curves );

                  $config = array(
                        "curve_name" => 'secp256k1',
                        "private_key_type" => OPENSSL_KEYTYPE_RSA,
                );

            $key = openssl_pkey_new( $config ) ;
            if ( $key ) {
      
                $details = openssl_pkey_get_details($key);

                openssl_pkey_export( $key, $str );

                echo $str;
            }
        ?>

    <p><?php esc_html_e( 'You can manage your repositories here', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <form method="post" action="options-general.php?page=api-privacy"> 
        <input type="hidden" name="juniper_author" value="1">
        <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">
        <table class="form-table" role="presentation">
            <tbody>
            <?php foreach( $this->settingsPages[ 'releases' ] as $name => $data ) { ?>
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
</div>
