// Pipeline de ASCLA (Jenkins multibranch del curso).
//
//   development -> despliegue
//   qa, uat     -> pruebas PHP + cobertura, SonarQube,
//                  Quality Gate y despliegue
//   main        -> todavía no despliega
//
// El .env de cada entorno debe almacenarse en Jenkins como credencial
// de tipo "Secret file": ASCLA_DEV, ASCLA_QA y ASCLA_UAT.
//
// SonarQube usa la configuracion global de Jenkins. Nunca guardar usuario,
// password ni token de SonarQube directamente en el repositorio.

pipeline {
    agent any

    options {
        buildDiscarder(logRotator(numToKeepStr: '5'))
        disableConcurrentBuilds()
    }

    environment {
        ENTORNO  = "${env.BRANCH_NAME == 'development' ? 'dev' : env.BRANCH_NAME}"
        PROYECTO = "ascla_${env.BRANCH_NAME == 'development' ? 'dev' : env.BRANCH_NAME}"
    }

    stages {
        stage('Checkout') {
            steps {
                checkout scm
            }
        }

        stage('Pruebas') {
            when {
                beforeAgent true
                anyOf {
                    branch 'qa'
                    branch 'uat'
                }
            }
            steps {
                sh '''
                    chmod +x scripts/quality-ci.sh
                    scripts/quality-ci.sh
                '''
            }
        }

        stage('SonarQube') {
            when {
                anyOf {
                    branch 'qa'
                    branch 'uat'
                }
            }
            environment {
                scannerHome = tool 'SonarScanner'
            }
            steps {
                withSonarQubeEnv('SonarQube-Server') {
                    sh "${scannerHome}/bin/sonar-scanner"
                }
            }
        }

        stage('Quality Gate') {
            when {
                anyOf {
                    branch 'qa'
                    branch 'uat'
                }
            }
            steps {
                timeout(time: 15, unit: 'MINUTES') {
                    waitForQualityGate abortPipeline: true
                }
            }
        }

        stage('Deploy') {
            when {
                anyOf {
                    branch 'development'
                    branch 'qa'
                    branch 'uat'
                }
            }
            steps {
                withCredentials([
                    file(
                        credentialsId: "ASCLA_${env.ENTORNO.toUpperCase()}",
                        variable: 'ENV_FILE'
                    )
                ]) {
                    sh '''
                        set -eu
                        set +x

                        dc() {
                            docker compose --env-file "$ENV_FILE" -p "$PROYECTO" "$@"
                        }

                        echo "Levantando ASCLA ($ENTORNO)..."
                        dc up -d --remove-orphans

                        echo "Esperando a que WordPress y la base de datos esten disponibles..."
                        INSTALADO=no
                        for i in $(seq 1 24); do
                            if dc run --rm cli \
                                sh /opt/ascla-scripts/init-wordpress.sh \
                                >/dev/null 2>&1; then
                                INSTALADO=si
                                break
                            fi

                            echo "  intento $i/24"
                            sleep 5
                        done

                        if [ "$INSTALADO" != "si" ]; then
                            echo "ERROR: no se pudo inicializar WordPress/ASCLA."
                            dc ps -a || true
                            dc logs --tail=80 --no-color || true
                            exit 1
                        fi

                        dc run --rm cli sh -c '
                            if [ "${ASCLA_SEED_DATA:-false}" = "true" ]; then
                                wp ascla seed
                            else
                                echo "Seed omitido (ASCLA_SEED_DATA=false)."
                            fi
                        '
                    '''
                }
            }
        }

        stage('Verificar despliegue') {
            when {
                anyOf {
                    branch 'development'
                    branch 'qa'
                    branch 'uat'
                }
            }
            steps {
                withCredentials([
                    file(
                        credentialsId: "ASCLA_${env.ENTORNO.toUpperCase()}",
                        variable: 'ENV_FILE'
                    )
                ]) {
                    sh '''
                        set +e
                        set +x

                        dc() {
                            docker compose --env-file "$ENV_FILE" -p "$PROYECTO" "$@"
                        }

                        CONTENEDOR=$(dc ps -q wordpress)
                        RESPONDE=no

                        if [ -z "$CONTENEDOR" ]; then
                            echo "ERROR: no se encontro el contenedor de WordPress."
                            exit 1
                        fi

                        echo "Esperando hasta 120 s a que WordPress/ASCLA quede operativo..."
                        for i in $(seq 1 24); do
                            CORRIENDO=$(docker inspect -f '{{.State.Running}}' "$CONTENEDOR" 2>/dev/null)
                            RESTARTS=$(docker inspect -f '{{.RestartCount}}' "$CONTENEDOR" 2>/dev/null)

                            HTTP_OK=no
                            CORE_OK=no
                            PLUGIN_OK=no

                            dc exec -T wordpress php -r '
                                $socket=@fsockopen("127.0.0.1",80,$errno,$errstr,3);
                                if ($socket) { fclose($socket); exit(0); }
                                exit(1);
                            ' >/dev/null 2>&1 && HTTP_OK=si

                            dc run --rm cli \
                                wp core is-installed --quiet \
                                >/dev/null 2>&1 && CORE_OK=si

                            dc run --rm cli \
                                wp plugin is-active ascla-core --quiet \
                                >/dev/null 2>&1 && PLUGIN_OK=si

                            if [ "$HTTP_OK" = "si" ] && \
                               [ "$CORE_OK" = "si" ] && \
                               [ "$PLUGIN_OK" = "si" ]; then
                                RESPONDE=si
                                break
                            fi

                            echo "  intento $i/24 -> Running=$CORRIENDO Restarts=$RESTARTS HTTP=$HTTP_OK Core=$CORE_OK Plugin=$PLUGIN_OK"

                            if [ "$CORRIENDO" != "true" ] || [ "${RESTARTS:-0}" -gt 0 ]; then
                                break
                            fi

                            sleep 5
                        done

                        echo ""
                        echo "Estado de los contenedores:"
                        dc ps -a

                        echo ""
                        echo "Ultimos logs:"
                        dc logs --tail=80 --no-color

                        if [ "$RESPONDE" != "si" ]; then
                            echo "ERROR: WordPress/ASCLA no quedo operativo."
                            exit 1
                        fi

                        echo "OK: WordPress esta instalado, ASCLA Core esta activo y Apache responde."
                    '''
                }
            }
        }
    }

    post {
        always {
            // Limpieza defensiva: el pipeline no copia las credenciales al workspace.
            sh 'rm -f .env || true'
        }
    }
}
