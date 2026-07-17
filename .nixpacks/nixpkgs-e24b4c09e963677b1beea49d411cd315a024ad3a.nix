{ }:

let pkgs = import (fetchTarball "https://github.com/NixOS/nixpkgs/archive/e24b4c09e963677b1beea49d411cd315a024ad3a.tar.gz") { overlays = [ (import (builtins.fetchTarball "https://github.com/railwayapp/nix-npm-overlay/archive/main.tar.gz")) ]; };
in with pkgs;
  let
    APPEND_LIBRARY_PATH = "${lib.makeLibraryPath [ libmysqlclient ] }";
    myLibraries = writeText "libraries" ''
      export LD_LIBRARY_PATH="${APPEND_LIBRARY_PATH}:$LD_LIBRARY_PATH"
      
    '';
  in
    buildEnv {
      name = "e24b4c09e963677b1beea49d411cd315a024ad3a-env";
      paths = [
        (runCommand "e24b4c09e963677b1beea49d411cd315a024ad3a-env" { } ''
          mkdir -p $out/etc/profile.d
          cp ${myLibraries} $out/etc/profile.d/e24b4c09e963677b1beea49d411cd315a024ad3a-env.sh
        '')
        (php83.withExtensions (pe: pe.enabled ++ [])) libmysqlclient nginx nodejs_18 npm-9_x php83Packages.composer
      ];
    }
