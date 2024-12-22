<div class="wrap juniper">
    <h1><?php esc_html_e( 'Author/Repositories', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can manage your repositories here', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <table class="repo-table striped wp-list-table widefat" role="presentation">
        <thead>
            <tr>
                <th><?php _e( 'Name', 'juniper' ); ?></th>
                <th><?php _e( 'Type', 'juniper' ); ?></th>
                <th><?php _e( 'URL', 'juniper' ); ?></th>
                <th><?php _e( 'Latest', 'juniper' ); ?></th>
                <th><?php _e( 'Issues', 'juniper' ); ?></th>
            </tr>
        </thead>
        <tbody>
            
        <?php foreach( $this->getSetting( 'repositories' ) as $name => $data ) { ?>
            <tr>
                <td><?php echo esc_html( $data->pluginInfo->pluginName ); ?></td>
                <td><?php echo esc_html( $data->type ); ?></td>
                <td><?php echo esc_html( $name ); ?></td>
                <td><?php echo esc_html( $data->pluginInfo->stableVersion ); ?></td>
                <td><?php echo esc_html( count( $data->issues ) ); ?></td>
            </tr>
        <?php }?> 
        </tbody>
    </table>

    <form method="post" action="admin.php?page=juniper-repos"> 
        <input type="hidden" name="juniper_author_settings" value="1">
        <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">
        <table class="form-table" role="presentation">
            <tbody>
            <?php foreach( $this->settingsPages[ 'repos' ] as $name => $data ) { ?>
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
        <input type="submit" id="submit" class="button button-primary" name="submit" value="<?php esc_attr_e( 'Add Repo', 'juniper' ); ?>" />
        <a href="admin.php?page=juniper-repos&juniper_action=refresh&juniper_nonce=<?php echo wp_create_nonce( 'juniper' ); ?>" class="button button-secondary" />Refresh</a>
    </form>
</div>