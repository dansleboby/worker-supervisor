package Cpanel::Admin::Modules::WorkerSupervisor::Workers;

# Privileged half of the Worker Supervisor UAPI: cpsrvd runs these functions as root and each
# one forwards to the PHP core (bin/wsv). No business logic lives here.

use strict;
use warnings;

use parent 'Cpanel::Admin::Base';

use Cpanel::JSON            ();
use Cpanel::SafeRun::Object ();

use constant _actions => qw(
  LIST
  GET
  VALIDATE
  SAVE
  DELETE
  START
  STOP
  RESTART
  LOGS
  CLEAR_LOGS
  TEMPLATES
);

my $WSV     = '/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv';
my $TIMEOUT = 30;

# Each function takes one hashref (the wsv "args") and returns wsv's decoded reply:
# { ok => true, data => ... } or { ok => false, error => { code, params, issues } }.
# Core failures, validation included, come back as data; only a missing feature throws.

sub LIST       { return $_[0]->_wsv( 'list',       $_[1] ) }
sub GET        { return $_[0]->_wsv( 'get',        $_[1] ) }
sub VALIDATE   { return $_[0]->_wsv( 'validate',   $_[1] ) }
sub SAVE       { return $_[0]->_wsv( 'save',       $_[1] ) }
sub DELETE     { return $_[0]->_wsv( 'delete',     $_[1] ) }
sub START      { return $_[0]->_wsv( 'start',      $_[1] ) }
sub STOP       { return $_[0]->_wsv( 'stop',       $_[1] ) }
sub RESTART    { return $_[0]->_wsv( 'restart',    $_[1] ) }
sub LOGS       { return $_[0]->_wsv( 'logs',       $_[1] ) }
sub CLEAR_LOGS { return $_[0]->_wsv( 'clear_logs', $_[1] ) }
sub TEMPLATES  { return $_[0]->_wsv( 'templates',  $_[1] ) }

sub _wsv {
    my ( $self, $action, $args ) = @_;

    # Unprivileged code can call admin functions without going through the UAPI module.
    $self->cpuser_has_feature_or_die('worker_supervisor');

    # $args goes through untouched: wsv treats it as untrusted and validates it. The user never
    # comes from the caller.
    my $request = Cpanel::JSON::Dump( { user => $self->get_caller_username(), args => $args } );

    my $run = eval {
        # Relies on SafeRun's default environment scrub: never pass keep_env (wsv's PHP honours PHPRC / PHP_INI_SCAN_DIR, supervisorctl honours PYTHONPATH).
        Cpanel::SafeRun::Object->new(
            program => $WSV,
            args    => [$action],
            stdin   => $request,
            timeout => $TIMEOUT,
        );
    };
    if ( !$run ) {
        warn "WorkerSupervisor $action: could not run $WSV: " . ( $@ || 'unknown error' ) . "\n";
        return _internal();
    }

    # wsv keeps diagnostics (paths, PHP errors) on stderr; they belong in cPanel's error_log only.
    my $stderr = $run->stderr();
    warn "WorkerSupervisor $action: $stderr" . ( $stderr =~ /\n\z/ ? '' : "\n" ) if length $stderr;

    if ( $run->timed_out() ) {
        warn "WorkerSupervisor $action: wsv killed after ${TIMEOUT}s\n";
        return _internal();
    }

    # Character strings on purpose: the admin reply path wants them, and UTF-8 byte strings
    # (LoadNoSetUTF8) reach the UAPI caller double-encoded (seen on cPanel 11.138).
    my $reply = eval { Cpanel::JSON::Load( $run->stdout() ) };
    if ( ref $reply ne 'HASH' || !exists $reply->{ok} || ( !$reply->{ok} && ref $reply->{error} ne 'HASH' ) ) {
        warn "WorkerSupervisor $action: unexpected wsv output ("
          . $run->terse_autopsy() . ', ' . length( $run->stdout() ) . " bytes)\n";
        return _internal();
    }

    return $reply;
}

sub _internal {
    return { ok => Cpanel::JSON::false(), error => { code => 'internal', params => {}, issues => [] } };
}

1;
