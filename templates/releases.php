<div class="wrap juniper releases">
    <h1><?php esc_html_e( 'Author/Releases', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can view and sign all releases here. If a repository does not show in this list, it is because it has no official Github releases associated with it.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <?php $repoInfo = $this->getSetting( 'repositories' ); ?>

    <div class="sign-area">
        <h2><?php _e( 'Sign Packages', 'juniper' ); ?></h2>

        <p><?php _e( 'To digitally sign the packages below, enter the passphrase for your private key and click Sign', 'juniper' ); ?></p>

        <form class="sign-form" method="post" action=""> 
            <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">

            <div class="one-setting">
                <?php _e( 'Enter password to use private key', 'juniper' ); ?></label>
                <input type="password" name="juniper_private_pw_1" id="juniper_private_pw_1" />  <label for="juniper_private_pw_1">
                <a href="#" class="button button-primary digitally-sign">Sign</a>
            </div>
        </form>   

        <div class="progress">
            <div class="bar">10%</div>
        </div> 
    </div>
    <?php $repositories = $this->getRepositories(); ?>
    <?php foreach( $repositories as $num => $repoInfo ) { ?>
        <?php if ( $repoInfo->releases && count( $repoInfo->releases ) ) { ?>
        <div class="one-repo">
            <h2 class="repo"><a href="<?php esc_attr_e( $repoInfo->repository->repoUrl ); ?>" target="_blank"><?php echo esc_html( $repoInfo->info->pluginName ); ?></a></h2>
            <div class="repo-loc"><?php echo esc_html( $repoInfo->repository->fullName ); ?></div>
        
            <table class="repo-table striped wp-list-table widefat releases" role="presentation">
                <thead>
                    <tr>
                        <th><?php _e( 'Tag', 'juniper' ); ?></th>
                        <th><?php _e( 'Release Info', 'juniper' ); ?></th>
                        <th><?php _e( 'Package', 'juniper' ); ?></th>
                        <th><?php _e( 'Release Date', 'juniper' ); ?></th>
                        <th><?php _e( 'Signed', 'juniper' ); ?></th>
                        <th><?php _e( 'Actions', 'juniper' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    
                <?php foreach( $repoInfo->releases as $actualRelease ) { ?>
                    <tr class="one-release<?php if ( $actualRelease->signed ) echo ' signed'; else echo ' unsigned'; ?>" data-repo="<?php esc_attr_e( $repoInfo->repository->fullName  ); ?>" data-tag="<?php esc_attr_e( $actualRelease->tag ); ?>">
                        <td><?php echo esc_html( $actualRelease->tag ); ?></td>
                        <td><?php echo esc_html( $actualRelease->name ); ?></td>
                        
                        <?php if ( $actualRelease->signed ) { ?>
                        <td class="package"><?php echo esc_html( basename( $actualRelease->signedName ) ); ?></td>
                        <?php } else { ?>
                        <td class="package"><?php echo esc_html( basename( $actualRelease->downloadUrl ) ); ?></td>
                        <?php } ?>
                        <td><?php echo date( 'M jS, Y', $actualRelease->publishedAt ); ?></td>
                        <?php if ( $actualRelease->signed ) { ?>
                            <td class="yesno"><span class="green"><?php _e( 'Yes', 'juniper' ); ?></span></td>
                            <td> <a class="verify" href="#" data-package="<?php echo esc_attr( $actualRelease->signedName ); ?>"><?php _e( 'Verify', 'juniper' ); ?></td>
                        <?php } else { ?>
                            <td class="yesno"><span class="red"><?php _e( 'No', 'juniper' ); ?></span></td>
                        <?php } ?>
                        <td></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    <?php }?> 
</div>
