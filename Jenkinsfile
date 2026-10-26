// Mandatory fail-closed image gate; QA/UAT also require SonarQube and isolated ZAP.
// Linux agent: Docker Compose, Python 3, Bash, OpenSSL, Git. Plugins: SonarQube, HTML Publisher.
// Secret file credentials: ASCLA_DEV, ASCLA_QA, ASCLA_UAT. Never store their values here.
pipeline {
    agent any
    options {
        skipDefaultCheckout(true)
        disableConcurrentBuilds()
        buildDiscarder(logRotator(numToKeepStr: '1', artifactNumToKeepStr: '1', removeLastBuild: true))
        timestamps()
        timeout(time: 120, unit: 'MINUTES')
        disableRestartFromStage()
    }
    stages {
        stage('Checkout') {
            steps {
                deleteDir()
                checkout scm
                script {
                    env.GIT_COMMIT = sh(returnStdout: true, script: 'git rev-parse HEAD').trim()
                    // A detached checkout has no local branch; Git metadata can be absent.
                    // Multibranch supplies BRANCH_NAME; single-branch jobs use their SCM spec.
                    def branch = env.BRANCH_NAME
                    if (!branch) {
                        def configured = scm.branches.collect { it.name }
                        if (configured.size() != 1) { error('Configure exactly one explicit SCM branch for ASCLA.') }
                        branch = configured[0].replaceFirst('^\\*/', '').replaceFirst('^origin/', '').replaceFirst('^refs/heads/', '')
                    }
                    if (!(branch in ['development', 'qa', 'uat', 'main'])) { error('ASCLA requires an explicit development, qa, uat or main branch.') }
                    env.ASCLA_BRANCH = branch
                    env.ENTORNO = env.ASCLA_BRANCH == 'development' ? 'dev' : env.ASCLA_BRANCH
                    env.PROYECTO = 'ascla_' + env.ENTORNO.replaceAll('[^a-zA-Z0-9_-]', '-')
                    env.SONAR_PROJECT = 'ASCLA-' + env.ASCLA_BRANCH.toUpperCase()
                }
                sh 'python3 -m unittest discover -s tests -p "test_ci_security.py" -v'
            }
        }
        stage('Construir imágenes') {
            steps { sh 'python3 scripts/ci-security.py build' }
        }
        stage('Controles obligatorios') {
            // Parallel branches all must pass; no failFast, catchError or skip parameters.
            parallel {
                stage('Trivy · imágenes Docker') {
                    steps { sh 'python3 scripts/ci-security.py scan' }
                }
                stage('Calidad · QA y UAT') {
                    when { expression { env.ASCLA_BRANCH in ['qa', 'uat'] } }
                    stages {
                        stage('Pruebas y cobertura') {
                            steps { sh 'bash scripts/quality-ci.sh' }
                        }
                        stage('SonarQube') {
                            environment { scannerHome = tool 'SonarScanner' }
                            steps {
                                sh 'test -s coverage/source-revision && test "$(cat coverage/source-revision)" = "$(git rev-parse HEAD)"'
                                withSonarQubeEnv(installationName: 'SonarQube-Server', credentialsId: "ASCLA_SONAR_${env.ASCLA_BRANCH.toUpperCase()}") {
                                    sh '"$scannerHome/bin/sonar-scanner" "-Dsonar.projectKey=$SONAR_PROJECT" "-Dsonar.projectName=$SONAR_PROJECT" "-Dsonar.scm.revision=$GIT_COMMIT"'
                                }
                            }
                        }
                        stage('Quality Gate') {
                            steps {
                                timeout(time: 15, unit: 'MINUTES') {
                                    waitForQualityGate abortPipeline: true, credentialsId: "ASCLA_SONAR_${env.ASCLA_BRANCH.toUpperCase()}"
                                }
                            }
                        }
                    }
                }
                stage('ZAP y CSP · QA y UAT') {
                    when { expression { env.ASCLA_BRANCH in ['qa', 'uat'] } }
                    steps {
                        withEnv(['ASCLA_CI_SECURITY=1']) { sh 'bash scripts/security-tests.sh' }
                    }
                    post {
                        always { sh 'python3 scripts/ci-publish-security.py' }
                    }
                }
            }
        }
        stage('Desplegar y verificar') {
            when { expression { env.ASCLA_BRANCH in ['development', 'qa', 'uat'] } }
            steps {
                sh 'python3 scripts/ci-security.py verify'
                withCredentials([file(credentialsId: "ASCLA_${env.ENTORNO.toUpperCase()}", variable: 'ENV_FILE')]) {
                    sh 'python3 scripts/ci-security.py deploy'
                }
            }
        }
    }
    post {
        always {
            script {
                try {
                    if (fileExists('test-results/ci-artifacts')) {
                        archiveArtifacts artifacts: 'test-results/ci-artifacts/**/*', fingerprint: true
                    }
                    if (fileExists('coverage/junit.xml')) {
                        junit testResults: 'coverage/junit.xml'
                        archiveArtifacts artifacts: 'coverage/*.xml,coverage/*.info,coverage/*summary.txt,coverage/source-revision', fingerprint: true
                    }
                    if (fileExists('.scannerwork/report-task.txt')) {
                        archiveArtifacts artifacts: '.scannerwork/report-task.txt', fingerprint: true
                    }
                    ['failure-login.json', 'failure-login.png', 'e2e.json', 'native-login-security.json'].each { diagnostic ->
                        def path = 'test-results/' + diagnostic
                        if (fileExists(path)) { archiveArtifacts artifacts: path, fingerprint: true }
                    }
                    if (fileExists('test-results/ci-artifacts/trivy/index.html')) {
                        publishHTML(target: [reportDir: 'test-results/ci-artifacts/trivy', reportFiles: 'index.html', reportName: 'Trivy · seguridad de imágenes', keepAll: false, alwaysLinkToLastBuild: true, allowMissing: false])
                    }
                    if (fileExists('test-results/ci-artifacts/zap/index.html')) {
                        publishHTML(target: [reportDir: 'test-results/ci-artifacts/zap', reportFiles: 'index.html', reportName: 'ZAP y CSP · seguridad web', keepAll: false, alwaysLinkToLastBuild: true, allowMissing: false])
                    }
                } finally {
                    try {
                        if (fileExists('scripts/ci-security.py')) { sh 'python3 scripts/ci-security.py clean' }
                    } finally {
                        deleteDir()
                    }
                }
            }
        }
    }
}
