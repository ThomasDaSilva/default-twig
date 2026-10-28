<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Tests\Http;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Model\Admin;
use Thelia\Model\AiToolApproval;
use Thelia\Model\AiToolApprovalQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Test\FixtureFactory;
use Thelia\Test\WebIntegrationTestCase;
use Thelia\Tests\Support\BackOffice\AdminSessionInjector;
use Thelia\Tools\TokenProvider;

/**
 * Configuration > Artificial intelligence > Approvals: what the AI proposed is read in full and escaped, a decision is
 * a POST with its token taken by the administrator of the session, and a proposal is decided once.
 */
final class AiApprovalScreenTest extends WebIntegrationTestCase
{
    private const SCRIPT = '<script>alert("pwned")</script>';

    private ?AdminSessionInjector $injector = null;

    private Admin $approver;

    private Admin $proposer;

    protected function setUp(): void
    {
        parent::setUp();

        if (ConfigQuery::read('active-admin-template') !== 'default-twig') {
            self::markTestSkipped('The Twig back-office is not the active admin template of the test shop: its routes are not registered.');
        }

        $this->injector = new AdminSessionInjector();
        $this->getService(EventDispatcherInterface::class)->addSubscriber($this->injector);
        $factory = new FixtureFactory($this->getPropelConnection());
        $this->proposer = $factory->admin(['login' => 'marie']);
        $this->approver = $factory->admin();
        $this->approver->eraseCredentials();
        $this->injector->setAdmin($this->approver);
    }

    protected function tearDown(): void
    {
        $this->injector?->clear();

        parent::tearDown();
    }

    public function testThePendingProposalsAreListedAndTheDetailShowsEverythingEscaped(): void
    {
        $id = $this->proposal(['summary' => 'Price of <b>MUG</b>', 'arguments' => json_encode(['pse_id' => 42, 'price' => self::SCRIPT], \JSON_THROW_ON_ERROR), 'after_state' => json_encode(['price' => self::SCRIPT, 'note' => 'tail-of-the-state'], \JSON_THROW_ON_ERROR)]);

        $this->assertPageRenders('/admin/configuration/ai/approvals');
        self::assertStringContainsString('data-approval="'.$id.'"', $this->html());

        $this->assertPageRenders('/admin/configuration/ai/approvals/'.$id);
        $html = $this->html();
        self::assertStringNotContainsString(self::SCRIPT, $html);
        self::assertStringContainsString('&lt;script&gt;alert(\\&quot;pwned\\&quot;)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('Price of <b>MUG</b>', $html);
        self::assertStringContainsString('tail-of-the-state', $html);
        self::assertStringContainsString('data-testid="ai-approval-arguments"', $html);
        self::assertStringContainsString('data-testid="ai-approval-after"', $html);
    }

    public function testRejectingNeedsItsTokenAndWritesTheApproverOfTheSession(): void
    {
        $id = $this->proposal();

        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/reject', ['note' => 'Too low']);
        self::assertSame('pending', AiToolApprovalQuery::create()->findPk($id)?->getStatus(), 'a form without its token decides nothing');

        // A forged approver in the form is ignored: the decision belongs to the administrator of the session.
        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/reject', ['note' => 'Too low', 'approver' => '999', 'decided_by_admin_id' => '999', '_token' => $this->token()]);
        $row = AiToolApprovalQuery::create()->findPk($id);
        self::assertSame('rejected', $row?->getStatus());
        self::assertSame('Too low', $row->getDecisionNote());
        self::assertSame($this->approver->getId(), $row->getDecidedByAdminId());
    }

    public function testAnApprovalWithoutItsTokenAppliesNothing(): void
    {
        $id = $this->proposal();

        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/approve');

        self::assertSame('pending', AiToolApprovalQuery::create()->findPk($id)?->getStatus());
    }

    public function testAProposalIsDecidedOnceAndTheSecondDecisionIsRefused(): void
    {
        $id = $this->proposal();
        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/reject', ['note' => 'first', '_token' => $this->token()]);
        self::assertSame('rejected', AiToolApprovalQuery::create()->findPk($id)?->getStatus());

        // The approval comes second: it must not turn a rejected proposal into an applied one.
        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/approve', ['_token' => $this->token()]);
        $this->client->followRedirect();

        $row = AiToolApprovalQuery::create()->findPk($id);
        self::assertSame('rejected', $row?->getStatus());
        self::assertSame('first', $row->getDecisionNote());
        self::assertStringContainsString('another decision was taken first', $this->html());
        self::assertStringNotContainsString('data-testid="ai-approval-approve"', $this->html(), 'a decided proposal offers no decision');
    }

    public function testAnApprovalThatFailsToApplyIsShownAsFailedAndNeverReplayed(): void
    {
        $id = $this->proposal(['target' => 'product_sale_elements:2147483000', 'arguments' => '{"pse_id":2147483000,"price":"9.00"}']);

        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/approve', ['_token' => $this->token()]);
        $this->client->followRedirect();

        self::assertSame('failed', AiToolApprovalQuery::create()->findPk($id)?->getStatus());
        self::assertStringContainsString('data-testid="ai-approval-error"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/approve', ['_token' => $this->token()]);
        self::assertSame('failed', AiToolApprovalQuery::create()->findPk($id)?->getStatus());
    }

    public function testAChangeLeftApplyingIsSignalledAndClosedByHandWithANote(): void
    {
        $id = $this->proposal(['status' => 'applying']);

        $this->assertPageRenders('/admin/configuration/ai/approvals');
        self::assertStringContainsString('data-testid="ai-approvals-applying"', $this->html());

        $this->assertPageRenders('/admin/configuration/ai/approvals/'.$id);
        self::assertStringContainsString('data-testid="ai-approval-close-form"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/close', ['note' => '', '_token' => $this->token()]);
        self::assertSame('applying', AiToolApprovalQuery::create()->findPk($id)?->getStatus(), 'closing needs a note');

        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/close', ['note' => 'Checked the shop: price unchanged.', '_token' => $this->token()]);
        $row = AiToolApprovalQuery::create()->findPk($id);
        self::assertSame('failed', $row?->getStatus());
        self::assertSame('Checked the shop: price unchanged.', $row->getError());
    }

    public function testAnAdministratorWithoutTheRightOfTheToolSeesNothingAndDecidesNothing(): void
    {
        $id = $this->proposal();
        $viewer = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([AdminResources::AI => [AccessManager::VIEW], AdminResources::PRODUCT => [AccessManager::VIEW]]);
        $viewer->eraseCredentials();
        $this->injector?->setAdmin($viewer);

        $this->assertPageRenders('/admin/configuration/ai/approvals');
        self::assertStringNotContainsString('data-approval="'.$id.'"', $this->html());

        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/reject', ['_token' => $this->token()]);
        self::assertSame('pending', AiToolApprovalQuery::create()->findPk($id)?->getStatus());
    }

    public function testAnAdministratorWithoutTheAiRightIsRefusedEverywhere(): void
    {
        $id = $this->proposal();
        $other = (new FixtureFactory($this->getPropelConnection()))->restrictedAdmin([AdminResources::PRODUCT => [AccessManager::VIEW, AccessManager::UPDATE]]);
        $other->eraseCredentials();
        $this->injector?->setAdmin($other);

        $this->client->request('GET', '/admin/configuration/ai/approvals');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('GET', '/admin/configuration/ai/approvals/'.$id);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/admin/configuration/ai/approvals/'.$id.'/reject', ['_token' => $this->token()]);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertSame('pending', AiToolApprovalQuery::create()->findPk($id)?->getStatus());
    }

    public function testTheToolsScreenListsTheDeclaredTools(): void
    {
        $this->assertPageRenders('/admin/configuration/ai/tools');

        self::assertStringContainsString('data-tool="thelia_update_product_price"', $this->html());
        self::assertStringContainsString('data-tool="thelia_search_products"', $this->html());
    }

    public function testTheRegisterAndTheUsagesCarryTheToolsColumn(): void
    {
        $this->assertPageRenders('/admin/configuration/ai/register');
        self::assertStringContainsString('data-testid="ai-register-tools"', $this->html());

        $this->assertPageRenders('/admin/configuration/ai/usages');
        self::assertStringContainsString('Tools together', $this->html());
    }

    /**
     * @param array<string, mixed> $values
     */
    private function proposal(array $values = []): int
    {
        $row = (new AiToolApproval())
            ->setToolName('thelia_update_product_price')
            ->setModule('thelia')
            ->setAclResource(AdminResources::PRODUCT)
            ->setAclAccess(AccessManager::UPDATE)
            ->setTarget($values['target'] ?? 'product_sale_elements:42')
            ->setSummary($values['summary'] ?? 'Price of MUG-THELIA: 16.5 → 14.9 EUR excl. tax')
            ->setOriginalArguments($values['arguments'] ?? '{"pse_id":42,"price":"14.90"}')
            ->setArguments($values['arguments'] ?? '{"pse_id":42,"price":"14.90"}')
            ->setBeforeState('{"price":"16.5"}')
            ->setAfterState($values['after_state'] ?? '{"price":"14.9"}')
            ->setStatus($values['status'] ?? 'pending')
            ->setChannel('mcp')
            ->setProposedByType('admin')
            ->setProposedByHash(str_repeat('a', 64))
            ->setProposedByAdminId($this->proposer->getId())
            ->setProposedByAdminLogin('marie')
            ->setExpiresAt(new \DateTimeImmutable('+1 day'));
        $row->save();

        return (int) $row->getId();
    }

    private function token(): string
    {
        return $this->getService(TokenProvider::class)->assignToken();
    }

    private function html(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
