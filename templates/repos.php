<div class="wrap juniper">
    <h1><?php esc_html_e( 'Author/Repositories', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can manage your Github repositories here.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <?php if ( $this->getSetting( 'github_token' ) ) { ?>

    <?php $repos = $this->getSetting( 'repositories' ); ?>
    <?php $hiddenRepos = $this->getSetting( 'hidden_repos' ); ?>
    <?php // print_r( $hiddenRepos ); ?>
    <?php if ( count ( $repos ) ) { ?>
    <table class="repo-table striped wp-list-table widefat" role="presentation">
        <thead>
            <tr>
                <th><?php _e( 'Name', 'juniper' ); ?></th>
                <th><?php _e( 'Type', 'juniper' ); ?></th>
                <th class="center"><?php _e( 'Authority', 'juniper' ); ?></th>
                <th class="center"><?php _e( 'Has Key', 'juniper' ); ?></th>
                <th class="desc"><?php _e( 'Description', 'juniper' ); ?></th>
                <th class="center"><?php _e( 'Latest', 'juniper' ); ?></th>
                <th class="center"><?php _e( 'Issues', 'juniper' ); ?></th>
                <th class="center"><?php _e( 'Actions', 'juniper' ); ?></th>
            </tr>
        </thead>
        <tbody>
            
        <?php foreach( $repos as $name => $data ) { ?>  
            <?php if ( in_array( $data->repository->fullName, $hiddenRepos ) ) continue; ?>
            <?php $name = ( !empty( $data->info->pluginName ) ? $data->info->pluginName : $data->info->themeName ); ?>
            <tr>
                <td><a href="<?php echo esc_attr( $data->repository->repoUrl ); ?>" target="_blank"><?php echo esc_html( $name ); ?></a</td>
                <td><?php echo esc_html( ucfirst( $data->info->type ) ); ?></td>
                <td class="center">
                    <?php if ( $data->info->signingAuthority ) { ?>
                    <?php _e( 'Yes', 'juniper' ); ?>
                    <?php } else { ?>
                    <span class=""><?php _e( 'No', 'juniper' ); ?></span>
                    <?php } ?>
                </td>
                <td class="center">
                    <?php if ( !empty( $data->info->publicKeys ) && $data->info->publicKeys ) { ?>
                    <?php _e( 'Yes', 'juniper' ); ?>
                    <?php } else { ?>
                    <span class=""><?php _e( 'No', 'juniper' ); ?></span>
                    <?php } ?>
                </td>
                <td>
                    <?php echo esc_html( $data->repository->description ); ?>
                    <?php if ( !$data->info->signingAuthority ) { ?>
                        <p class="info">
                            <?php _e( 'Note: This plugin needs to add an "Authority: " header in the main file pointing to this site:', 'juniper' ); ?><br>
                            Authority: <?php echo home_url(); ?>
                        </p>
                    <?php } ?>
                </td>
                <td class="center"><?php echo esc_html( $data->info->stableVersion ); ?></td>
                <td class="center"><?php echo esc_html( count( $data->issues ) ); ?></td>
                <td class="center"><a href="#" class="remove-repo" data-repo="<?php esc_attr_e( $data->repository->fullName ); ?>"><?php _e( 'Remove', 'juniper' ); ?></a</td>
            </tr>
        <?php }?> 
        </tbody>
    </table>
    <?php } else { ?>
        <p><?php _e( 'If this is your first time viewing this page, please click "Update Repository Info" to import your Github repositories.', 'juniper' ); ?></p>
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

            <?php if ( count( $hiddenRepos ) ) {  ?>   
            <div class="hidden-repos">
                <h2>Hidden Repositories</h2>
                <ul>
                <?php foreach( $hiddenRepos as $repo ) { ?>
                    <li><?php esc_html_e( $repo ); ?> - <a href="#" class="restore-repo" data-repo="<?php esc_attr_e( $repo ); ?>"><?php _e( 'Restore', 'juniper' ); ?></a></li>
                <?php } ?>
                </ul>
            <br>
            </div>
            <?php } ?> 

        <p><?php _e( 'To prevent duplicates in the repository, in a future release only plugins with a designated Authority header will be includled by that site.', 'junper' ); ?></p>

        <a href="admin.php?page=juniper-repos&juniper_action=refresh&juniper_nonce=<?php echo wp_create_nonce( 'juniper' ); ?>" class="do-ajax button button-primary" data-stage="0" /><?php _e( 'Update Repository Info', 'juniper' ); ?></a>
        <?php if ( $repos && count( $repos ) ) { ?>
        <a href="admin.php?page=juniper-repos&juniper_action=submit&juniper_nonce=<?php echo wp_create_nonce( 'juniper' ); ?>" class="button button-secondary" /><?php _e( 'Submit To Mirror', 'juniper' ); ?></a>
        <?php } ?>
    </form>

    <?php } else { ?>
        <?php /* translators: can be ignored as it will be substituted with a hyperlink */ ?>
        <p><?php echo sprintf( __( 'Please configure your Github token in the %sAuthorship Options%s area first.', 'juniper' ), '<a href="' . admin_url( 'admin.php?page=juniper-options' ) . '">', '</a>' ); ?></p>
    <?php } ?>

    <div id="debug-area" style="display: none">
        <h2>Debug</h2>
        <textarea class="debug" rows="10" id="repo_debug" readonly>
        </textarea>
    </div>
</div>
