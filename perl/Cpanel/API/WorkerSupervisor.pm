package Cpanel::API::WorkerSupervisor;

# UAPI face of Worker Supervisor. Each function maps its flat parameters onto a wsv request and
# hands it to the admin module (Cpanel::Admin::Modules::WorkerSupervisor::Workers), which runs
# the PHP core as root. Nothing else happens here.
#
# Success: status 1, data = the core's data. Failure: status 0, errors = [code],
# data = { error => { code, params, issues } }; a missing feature is code feature_disabled.

use strict;
use warnings;

our $VERSION = '1.0';

use Cpanel                 ();    # main::hasfeature
use Cpanel::AdminBin::Call ();

my $FEATURE = 'worker_supervisor';

# Worker fields arrive flat and travel to the core grouped under args.input.
my @INPUT_KEYS = qw(
  name template folder command processes autostart autorestart
  startsecs startretries stopwaitsecs redirect_stderr expert_text
);

# The core wants JSON integers here, but every UAPI parameter arrives as a string.
my %INT_KEYS = map { $_ => 1 } qw(process lines);

sub list_workers {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'LIST' );
}

sub list_templates {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'TEMPLATES' );
}

sub get_worker {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'GET', ['name'] );
}

# Form mode: worker fields (name included) as flat parameters. Expert mode: template and
# expert_text. original_name, when set, names the stored worker being edited.
sub validate_worker {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'VALIDATE', [qw(mode original_name)], 1 );
}

sub save_worker {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'SAVE', [qw(mode original_name)], 1 );
}

sub delete_worker {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'DELETE', ['name'] );
}

sub start_worker {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'START', ['name'] );
}

sub stop_worker {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'STOP', ['name'] );
}

sub restart_worker {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'RESTART', ['name'] );
}

sub get_logs {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'LOGS', [qw(name process stream lines)] );
}

sub clear_logs {
    my ( $args, $result ) = @_;
    return _forward( $args, $result, 'CLEAR_LOGS', ['name'] );
}

# Absent parameters are left out so the core applies its own defaults.
sub _forward {
    my ( $args, $result, $action, $keys, $with_input ) = @_;

    if ( !main::hasfeature($FEATURE) ) {
        $result->data( { error => { code => 'feature_disabled', params => {}, issues => [] } } );
        $result->raw_error('feature_disabled');
        return 0;
    }

    my %request = map { $_ => _param( $args, $_ ) } grep { $args->exists($_) } @{ $keys || [] };
    if ($with_input) {
        $request{input} = { map { $_ => scalar $args->get($_) } grep { $args->exists($_) } @INPUT_KEYS };
    }

    my $reply = Cpanel::AdminBin::Call::call( 'WorkerSupervisor', 'Workers', $action, \%request );

    if ( $reply->{ok} ) {
        $result->data( $reply->{data} );
        return 1;
    }

    my $err = $reply->{error};
    $result->data( { error => $err } );
    $result->raw_error( $err->{code} );
    return 0;
}

sub _param {
    my ( $args, $key ) = @_;
    my $value = $args->get($key);
    return $INT_KEYS{$key} && defined $value && $value =~ /\A[0-9]+\z/ ? 0 + $value : $value;
}

1;
