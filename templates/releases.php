<div class="wrap juniper">
    <h1><?php esc_html_e( 'Author/Releases', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can view and sign all releases here.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <?php $repoInfo = $this->getSetting( 'repositories' ); ?>
    
    <?php foreach( $this->getSetting( 'releases' ) as $repo => $releaseInfo ) { ?>
        <h2><?php echo esc_html( $repoInfo[ $repo ]->pluginName ); ?></h2>
        <table class="repo-table striped wp-list-table widefat" role="presentation">
            <thead>
                <tr>
                    <th><?php _e( 'Tag', 'juniper' ); ?></th>
                    <th><?php _e( 'Release Info', 'juniper' ); ?></th>
                    <th><?php _e( 'Package', 'juniper' ); ?></th>
                    <th><?php _e( 'Release Date', 'juniper' ); ?></th>
                    <th><?php _e( 'Signed', 'juniper' ); ?></th>
                </tr>
            </thead>
            <tbody>
                
            <?php foreach( $releaseInfo as $actualRelease ) { ?>
                <tr>
                    <td><?php echo esc_html( $actualRelease->tag_name ); ?></td>
                    <td><?php echo esc_html( $actualRelease->name ); ?></td>
                    
                    <td><?php echo esc_html( $actualRelease->assets[0]->name ); ?></td>
                    <td><?php echo date( 'M jS, Y', strtotime( $actualRelease->published_at ) ); ?></td>
                    <td class="red"><?php _e( 'No', 'juniper' ); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php }?> 
</div>
