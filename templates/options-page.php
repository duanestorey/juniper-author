<div class="wrap">
    <h1><?php esc_html_e( 'Juniper/Author', 'juniper' ); ?></h1>

    <p>
        <?php esc_html_e( 'You can configure the options for Juniper/Author here.', 'juniper' ); ?><br />
    </p>

    <?php $this->doOptionsHeader(); ?>

    <form method="post" action="options-general.php?page=api-privacy"> 
        <input type="hidden" name="juniper_author" value="1">
        <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">
        <table class="form-table" role="presentation">
            <tbody>
                <?php foreach( $this->settingsSections as $name => $data ) { ?>
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
