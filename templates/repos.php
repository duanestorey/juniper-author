<div class="wrap juniper">
    <h1><?php esc_html_e( 'Author/Repositories', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can manage your Github repositories here.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <?php if ( $this->getSetting( 'github_token' ) ) { ?>

    <?php $repos = $this->getSetting( 'repositories' ); ?>
    <?php if ( count ( $repos ) ) { ?>
    <table class="repo-table striped wp-list-table widefat" role="presentation">
        <thead>
            <tr>
                <th><?php _e( 'Name', 'juniper' ); ?></th>
                <th><?php _e( 'Type', 'juniper' ); ?></th>
                <th class="desc"><?php _e( 'Description', 'juniper' ); ?></th>
                <th><?php _e( 'Latest', 'juniper' ); ?></th>
                <th><?php _e( 'Issues', 'juniper' ); ?></th>
            </tr>
        </thead>
        <tbody>
            
        <?php foreach( $repos as $name => $data ) { ?>
            <tr>
                <td><a href="<?php echo esc_attr( $data->repository->repoUrl ); ?>" target="_blank"><?php echo esc_html( $data->info->pluginName ); ?></a</td>
                <td><?php echo esc_html( strtoupper( $data->info->type ) ); ?></td>
                <td><?php echo esc_html( $data->repository->description ); ?></td>
                <td><?php echo esc_html( $data->info->stableVersion ); ?></td>
                <td><?php echo esc_html( count( $data->issues ) ); ?></td>
            </tr>
        <?php }?> 
        </tbody>
    </table>
    <?php } else { ?>
        <p><?php _e( 'If this is your first time viewing this page, please do a Full Refresh to import your Github repositories.', 'juniper' ); ?></p>
    <?php } ?>
    <br />

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
        <a href="admin.php?page=juniper-repos&juniper_action=refresh&juniper_nonce=<?php echo wp_create_nonce( 'juniper' ); ?>" class="do-ajax button button-primary" data-stage="0" />Full Refresh (Repos/Issues/Releases)</a>
        <a href="admin.php?page=juniper-repos&juniper_action=partial_refresh&juniper_nonce=<?php echo wp_create_nonce( 'juniper' ); ?>" class="do-ajax button button-primary" data-stage="10" />Partial Refresh (Issues/Releases)</a>
        <?php if ( $repos && count( $repos ) ) { ?>
        <a href="admin.php?page=juniper-repos&juniper_action=submit&juniper_nonce=<?php echo wp_create_nonce( 'juniper' ); ?>" class="button button-secondary" />Submit To Mirror</a>
        <?php } ?>
    </form>

    <?php } else { ?>
        <?php /* translators: can be ignored as it will be substituted with a hyperlink */ ?>
        <p><?php echo sprintf( __( 'Please configure your Github token in the %1$sAuthorship Options%$2s area first.', 'juniper' ), '<a href="' . admin_url( 'admin.php?page=juniper-options' ) . '">', '</a>' ); ?></p>
    <?php } ?>

    <div id="debug-area" style="display: none">
        <h2>Debug</h2>
        <textarea class="debug" rows="10" id="repo_debug" readonly>
        </textarea>
    </div>
</div>
