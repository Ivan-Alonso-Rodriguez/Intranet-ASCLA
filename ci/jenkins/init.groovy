import jenkins.model.Jenkins
import hudson.security.HudsonPrivateSecurityRealm
import hudson.security.FullControlOnceLoggedInAuthorizationStrategy
import org.jenkinsci.plugins.workflow.job.WorkflowJob
import org.jenkinsci.plugins.workflow.cps.CpsScmFlowDefinition
import hudson.plugins.git.GitSCM

def instance = Jenkins.get()
def realm = new HudsonPrivateSecurityRealm(false)
realm.createAccount('ascla.local', new File('/run/secrets/ascla-admin-password').text.trim())
instance.setSecurityRealm(realm)
def authorization = new FullControlOnceLoggedInAuthorizationStrategy()
authorization.setAllowAnonymousRead(false)
instance.setAuthorizationStrategy(authorization)
instance.setNumExecutors(2)
instance.setQuietPeriod(0)
def job = instance.getItem('ascla-local') ?: instance.createProject(WorkflowJob, 'ascla-local')
def scm = new GitSCM('/source')
scm.branches = [new hudson.plugins.git.BranchSpec('*/main')]
job.setDefinition(new CpsScmFlowDefinition(scm, 'Jenkinsfile'))
job.save()
instance.save()
