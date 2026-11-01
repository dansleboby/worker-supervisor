package Cpanel::Pkgacct::Components::WorkerSupervisor;

# Custom pkgacct component: puts the account's Worker Supervisor workers in its cpmove archive
# as worker_supervisor.json, for cPanel backups, transfers and JetBackup (whose account
# configuration is built with pkgacct). bin/wsv-hook reads the file back on PkgAcct::Restore.
#
# A backup must never fail because of the plugin: every problem is a warning in the pkgacct
# output, and the archive simply has no workers file.

use strict;
use warnings;

use parent 'Cpanel::Pkgacct::Component';

use Cpanel::JSON            ();
use Cpanel::SafeRun::Object ();
use Cwd                     ();
use Errno                   ();
use Fcntl qw(O_WRONLY O_CREAT O_EXCL O_NOFOLLOW);

my $WSV     = '/usr/local/cpanel/3rdparty/worker_supervisor/bin/wsv';
my $FILE    = 'worker_supervisor.json';
my $TIMEOUT = 60;

sub perform {
    my ($self) = @_;

    # The workers are root-only data: an unprivileged pkgacct skips them.
    return 1 if $> != 0 || !-x $WSV;

    my $user  = $self->get_user();
    my $error = eval { _export( $user, $self->get_work_dir() ); 1 } ? undef : ( $@ || 'unknown error' );
    if ( defined $error ) {
        chomp $error;
        $self->get_output_obj()->warn("Worker Supervisor: the workers of $user are not in this backup: $error\n");
    }

    return 1;
}

sub _export {
    my ( $user, $dir ) = @_;

    my $real = Cwd::abs_path($dir) // die "cannot resolve $dir\n";
    die "$real is not a root-only directory\n" if !_root_only($real);
    my $path = "$real/$FILE";

    # Incremental backups reuse their directory: a file from an earlier run must never stay, even
    # when this export fails, or a restore would bring back outdated workers.
    unlink($path) or $!{ENOENT} or die "cannot remove the earlier $path: $!\n";

    # Relies on SafeRun's default environment scrub: never pass keep_env.
    my $run = Cpanel::SafeRun::Object->new(
        program => $WSV,
        args    => ['backup_export'],
        stdin   => Cpanel::JSON::Dump( { user => $user } ),
        timeout => $TIMEOUT,
    );
    die "wsv backup_export failed (" . $run->terse_autopsy() . ")\n" if $run->CHILD_ERROR() || $run->timed_out();

    # Character strings: Dump encodes them back to UTF-8; byte strings (LoadNoSetUTF8) come out
    # double-encoded (checked on cPanel 11.138).
    my $reply = Cpanel::JSON::Load( $run->stdout() );
    die "unexpected wsv output\n" if ref $reply ne 'HASH' || !$reply->{ok};
    return if !defined $reply->{data};

    # Root writes here: never through a symlink, never into a file someone placed.
    my $tmp = "$real/.$FILE.$$";
    unlink($tmp) or $!{ENOENT} or die "cannot remove $tmp: $!\n";
    sysopen( my $fh, $tmp, O_WRONLY | O_CREAT | O_EXCL | O_NOFOLLOW, 0600 ) or die "cannot create $tmp: $!\n";
    my $written = print {$fh} Cpanel::JSON::Dump( $reply->{data} );
    if ( !close($fh) || !$written || !rename( $tmp, $path ) ) {
        my $why = $!;
        unlink $tmp;
        die "cannot write $path: $why\n";
    }

    return;
}

# Whether every directory from / to $dir is root's and writable by root alone: otherwise its owner
# could swap a path component for a directory holding a symlink where root writes. cPanel builds
# even a user's own full backup in a root-only directory (11.138), so this only refuses oddities.
sub _root_only {
    my ($dir) = @_;
    my $path = $dir;
    while (1) {
        my @st = lstat($path);
        return 0 if !@st || !-d _ || $st[4] != 0 || ( $st[2] & 022 );
        return 1 if $path eq '/';
        $path =~ s{/[^/]*\z}{};
        $path = '/' if $path eq '';
    }
}

1;
