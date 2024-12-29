<div class="wrap juniper releases">
    <h1><?php esc_html_e( 'Author/Releases', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can view and sign all releases here. If a repository does not show in this list, it is because it has no official Github releases associated with it.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <?php $repoInfo = $this->getSetting( 'repositories' ); ?>

    <?php if ( $this->getSetting( 'password_salt' ) ) { ?>
    <div class="sign-area">
        <h2><?php _e( 'Sign Packages', 'juniper' ); ?></h2>

        <p><?php _e( 'To digitally sign the packages below, enter the passphrase for your private key and click Sign', 'juniper' ); ?></p>

        <form class="sign-form" method="post" action=""> 
            <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">

            <div class="one-setting">
                <?php _e( 'Enter password to use private key', 'juniper' ); ?></label>
                <input type="password" name="juniper_private_pw_1" id="juniper_private_pw_1" />  <label for="juniper_private_pw_1">
                <a href="#" class="button button-primary digitally-sign" data-type="new"><?php _e( 'Sign New', 'juniper' ); ?></a>
                <a href="#" class="button button-primary digitally-sign" data-type="all"><?php _e( 'Sign/Resign All', 'juniper' ); ?></a>
            </div>
        </form>   

        <p><strong><?php _e( 'This feature is experimental and currently undergoing rapid development.', 'juniper' ); ?></strong></p>

        <div class="progress">
            <div class="bar">10%</div>
        </div> 
    </div>
    <?php } ?>
    <?php $repositories = $this->getRepositories(); ?>
    <?php foreach( $repositories as $num => $repoInfo ) { ?>
        <?php if ( $repoInfo->releases && count( $repoInfo->releases ) ) { ?>
        <div class="one-repo">
            <h2 class="repo"><a href="<?php esc_attr_e( $repoInfo->repository->repoUrl ); ?>" target="_blank"><?php echo esc_html( $repoInfo->info->pluginName ); ?></a></h2>
            <div class="repo-loc"><?php echo esc_html( $repoInfo->repository->fullName ); ?></div>
        
            <table class="repo-table striped wp-list-table widefat releases" role="presentation">
                <thead>
                    <tr>
                        <th class="col1"><?php _e( 'Tag', 'juniper' ); ?></th>
                        <th class="col2"><?php _e( 'Release Info', 'juniper' ); ?></th>
                        <th class="col1"><?php _e( 'Release Date', 'juniper' ); ?></th>
                        <th class="col1"><?php _e( 'Package', 'juniper' ); ?></th>          
                        <th class="col1"><?php _e( 'Signed', 'juniper' ); ?></th>
                        <th class="col1"><?php _e( 'Signed Date', 'juniper' ); ?></th>
                        <th class="col1"><?php _e( 'Actions', 'juniper' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    
                <?php foreach( $repoInfo->releases as $actualRelease ) { ?>
                    <tr class="one-release<?php if ( $actualRelease->signed ) echo ' signed'; else echo ' unsigned'; ?>" data-repo="<?php esc_attr_e( $repoInfo->repository->fullName  ); ?>" data-tag="<?php esc_attr_e( $actualRelease->tag ); ?>">
                        <td><?php echo esc_html( $actualRelease->tag ); ?></td>
                        <td><a href="<?php esc_attr_e( $actualRelease->url ); ?>" target="_blank"><?php echo esc_html( $actualRelease->name ); ?></a></td>
                        <td><?php echo date( 'M jS, Y', $actualRelease->publishedAt ); ?></td>
                        <td class="package"><?php echo esc_html( basename( $actualRelease->downloadUrl ) ); ?></td>
                       
                        <?php if ( $actualRelease->signed ) { ?>
                            <td class="yesno"><span class="green"><?php _e( 'Yes', 'juniper' ); ?></span></td>
                            <td><?php echo date( 'M jS, Y', $actualRelease->signedDate ); ?></td>
                            <td> <a class="verify" href="#" data-package="<?php echo esc_attr(  $repoInfo->repository->fullName . '/' . $actualRelease->tag . '/' . basename( $actualRelease->downloadUrl ) ); ?>"><?php _e( 'Verify', 'juniper' ); ?></td>
                        <?php } else { ?>
                            <td class="yesno"><span class="red"><?php _e( 'No', 'juniper' ); ?></span></td>
                            <td></td>
                            <td></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <?php } ?>
    <?php }?> 
</div>
